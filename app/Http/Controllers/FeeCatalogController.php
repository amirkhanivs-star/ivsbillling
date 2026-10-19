<?php

namespace App\Http\Controllers;

use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FeeCatalogController
{
    private function authorizeWrite(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'finance_admin'], true), 403);
    }

    public function index(Request $request)
    {
        $types = DB::table('fee_types')->orderBy('name')->get();
        $classes = DB::table('programs')->select('name')->union(DB::table('enrollments')->whereNotNull('grade_label')->selectRaw('grade_label as name'))->orderBy('name')->pluck('name')->filter()->unique()->values();
        if ($classes->isEmpty()) {
            $classes = collect(['FS 1', 'FS 2', 'FS 3', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5']);
        }
        $departments = DB::table('departments')->orderBy('id')->get();
        $selectedType = $request->integer('type') ?: optional($types->firstWhere('code', 'monthly'))->id;

        // Ensure every class has an entry with by-default value 0 for the selected fee type
        if ($selectedType) {
            $defaultDept = $departments->first()?->id ?? 1;
            $selectedTypeObj = $types->firstWhere('id', $selectedType);
            $freq = ($selectedTypeObj && $selectedTypeObj->code === 'monthly') ? 'monthly' : 'one_time';
            foreach ($classes as $cls) {
                if (!DB::table('fee_catalog_rates')->where('fee_type_id', $selectedType)->where('class_label', $cls)->exists()) {
                    DB::table('fee_catalog_rates')->insert([
                        'fee_type_id' => $selectedType,
                        'department_id' => $defaultDept,
                        'class_label' => $cls,
                        'currency' => 'SAR',
                        'amount_minor' => 0,
                        'frequency' => $freq,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $query = DB::table('fee_catalog_rates as c')->join('fee_types as t', 't.id', '=', 'c.fee_type_id')
            ->join('departments as d', 'd.id', '=', 'c.department_id')
            ->select('c.*', 't.name as type_name', 'd.name as department_name');
        if ($selectedType) $query->where('c.fee_type_id', $selectedType);
        if ($request->filled('search')) $query->where('c.class_label', 'like', '%'.addcslashes($request->input('search'), '%_').'%');
        $rates = $query->orderBy('c.class_label')->paginate(30)->withQueryString();
        $plans = DB::table('fee_plan_items as p')->join('enrollments as e', 'e.id', '=', 'p.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')->select('p.id', 'p.fee_type_id', 'p.amount_minor', 'p.currency', 'e.grade_label', 'e.department_id', 'e.campus_id', 's.name', 's.gr_number')
            ->where('p.is_active', true)->orderBy('s.name')->limit(300)->get();
        return view('fee-structure', compact('types', 'classes', 'departments', 'rates', 'selectedType', 'plans'));
    }

    public function store(Request $request)
    {
        $this->authorizeWrite($request);
        $data = $request->validate([
            'title' => 'required|string|max:180',
            'amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'frequency' => Rule::in(['monthly', 'one_time', 'annual', 'quarterly', 'weekly', 'custom']),
            'department_id' => 'required|exists:departments,id',
            'class_label' => 'required|string|max:120',
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
        ]);
        return DB::transaction(function () use ($data) {
            $code = Str::slug($data['title'], '_');
            $type = DB::table('fee_types')->whereNull('department_id')->where('code', $code)->first();
            if (!$type) {
                $id = DB::table('fee_types')->insertGetId(['name' => $data['title'], 'code' => substr($code, 0, 48), 'department_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            } else $id = $type->id;
            $key = ['fee_type_id' => $id, 'department_id' => $data['department_id'], 'class_label' => $data['class_label'], 'currency' => $data['currency']];
            if (DB::table('fee_catalog_rates')->where($key)->exists()) return back()->withErrors(['class_label' => 'A fee for this class, type and currency already exists. Use Edit instead.']);
            DB::table('fee_catalog_rates')->insert($key + ['amount_minor' => Money::minor($data['amount']), 'frequency' => $data['frequency'], 'created_at' => now(), 'updated_at' => now()]);
            return redirect('/manage-fee?type='.$id)->with('notice', 'Class fee added. Existing student plans and invoices were not changed.');
        });
    }

    public function update(Request $request, int $rate)
    {
        $this->authorizeWrite($request);
        $data = $request->validate(['amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'], 'reason' => 'nullable|string|max:255']);
        DB::transaction(function () use ($request, $rate, $data) {
            $row = DB::table('fee_catalog_rates')->where('id', $rate)->lockForUpdate()->first();
            abort_unless($row, 404);
            $after = Money::minor($data['amount']);
            DB::table('fee_rate_changes')->insert(['fee_type_id' => $row->fee_type_id, 'user_id' => $request->user()->id, 'target_kind' => 'catalog', 'target_id' => $rate, 'currency' => $row->currency, 'before_minor' => $row->amount_minor, 'after_minor' => $after, 'reason' => $data['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fee_catalog_rates')->where('id', $rate)->update(['amount_minor' => $after, 'updated_at' => now()]);
        });
        return back()->with('notice', 'Class rate updated. Existing student fee plans and issued invoices are unchanged.');
    }

    public function increment(Request $request)
    {
        $this->authorizeWrite($request);
        $data = $request->validate([
            'fee_type_id' => 'required|exists:fee_types,id',
            'class_labels' => 'required|array|min:1', 'class_labels.*' => 'required|string|max:120',
            'amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'reason' => 'nullable|string|max:255',
        ]);
        $increase = Money::minor($data['amount']);
        if ($increase === 0) return back()->withErrors(['amount' => 'Increment must be greater than zero.']);
        $count = DB::transaction(function () use ($data, $request, $increase) {
            $rows = DB::table('fee_plan_items as p')->join('enrollments as e', 'e.id', '=', 'p.enrollment_id')
                ->where('p.fee_type_id', $data['fee_type_id'])->where('p.is_active', true)->where('e.status', 'active')
                ->whereIn('e.grade_label', $data['class_labels'])->select('p.id', 'p.currency', 'p.amount_minor')->lockForUpdate()->get();
            foreach ($rows as $plan) {
                $after = $plan->amount_minor + $increase;
                DB::table('fee_rate_changes')->insert(['fee_type_id' => $data['fee_type_id'], 'user_id' => $request->user()->id, 'target_kind' => 'student_plan', 'target_id' => $plan->id, 'currency' => $plan->currency, 'before_minor' => $plan->amount_minor, 'after_minor' => $after, 'reason' => $data['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('fee_plan_items')->where('id', $plan->id)->update(['amount_minor' => $after, 'updated_at' => now()]);
            }
            return $rows->count();
        });
        return back()->with('notice', "Updated {$count} active student fee plans. Previously generated invoice amounts are unchanged.");
    }

    public function destroy(Request $request, int $rate)
    {
        $this->authorizeWrite($request);
        DB::transaction(function () use ($request, $rate) {
            $row = DB::table('fee_catalog_rates')->where('id', $rate)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('fee_rate_changes')->insert([
                'fee_type_id' => $row->fee_type_id,
                'user_id' => $request->user()->id,
                'target_kind' => 'catalog_deleted',
                'target_id' => $rate,
                'currency' => $row->currency,
                'before_minor' => $row->amount_minor,
                'after_minor' => 0,
                'reason' => 'Deleted / reset to default zero',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('fee_catalog_rates')->where('id', $rate)->delete();
        });
        return back()->with('notice', 'Class fee deleted.');
    }

    public function destroyType(Request $request, int $type)
    {
        $this->authorizeWrite($request);
        $typeObj = DB::table('fee_types')->where('id', $type)->first();
        abort_unless($typeObj, 404);

        if (in_array($typeObj->code, ['monthly', 'registration', 'admission', 'books', 'exam', 'slc_charges', 'custom'], true)) {
            return back()->withErrors(['error' => 'Core fee types cannot be deleted.']);
        }

        DB::transaction(function () use ($type) {
            DB::table('fee_catalog_rates')->where('fee_type_id', $type)->delete();
            DB::table('fee_plan_items')->where('fee_type_id', $type)->delete();
            DB::table('fee_types')->where('id', $type)->delete();
        });

        return redirect('/manage-fee')->with('notice', 'Fee type and its rates deleted successfully.');
    }
}
