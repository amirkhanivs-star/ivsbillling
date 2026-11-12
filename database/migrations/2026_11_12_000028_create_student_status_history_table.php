<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('student_status_history')) {
            Schema::create('student_status_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->string('event_type', 32); // 'left', 'readmitted'
                $table->date('effective_date');
                $table->text('leaving_reason')->nullable();
                $table->string('leaving_category', 100)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['student_id', 'effective_date']);
                $table->index(['student_id', 'event_type']);
            });
        }

        // Reconstruct historical events from audit_logs and existing student records if available
        $auditLogs = DB::table('audit_logs')
            ->whereIn('action', ['student_marked_left', 'student_readmitted'])
            ->where('subject_type', 'student')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($auditLogs as $log) {
            $studentId = (int) $log->subject_id;
            if (!DB::table('students')->where('id', $studentId)->exists()) {
                continue;
            }

            $meta = [];
            if (!empty($log->metadata)) {
                $meta = is_string($log->metadata) ? (json_decode($log->metadata, true) ?: []) : (array) $log->metadata;
            }

            $isLeft = ($log->action === 'student_marked_left');
            $eventType = $isLeft ? 'left' : 'readmitted';
            $fallbackDate = $log->created_at ? substr((string) $log->created_at, 0, 10) : now()->toDateString();
            $effectiveDate = $isLeft
                ? ($meta['date_of_leaving'] ?? $fallbackDate)
                : ($meta['readmission_date'] ?? $fallbackDate);

            $reason = $isLeft ? ($meta['leaving_reason'] ?? $meta['reason'] ?? null) : null;
            $category = $isLeft ? ($meta['leaving_category'] ?? $meta['category'] ?? null) : null;
            $actorId = ($log->actor_id && DB::table('users')->where('id', $log->actor_id)->exists())
                ? $log->actor_id
                : null;

            $alreadyRecorded = DB::table('student_status_history')
                ->where('student_id', $studentId)
                ->where('event_type', $eventType)
                ->where('effective_date', $effectiveDate)
                ->where('created_at', $log->created_at)
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            DB::table('student_status_history')->insert([
                'student_id' => $studentId,
                'event_type' => $eventType,
                'effective_date' => $effectiveDate,
                'leaving_reason' => $reason,
                'leaving_category' => $category,
                'user_id' => $actorId,
                'actor_id' => $actorId,
                'metadata' => !empty($meta) ? json_encode($meta) : null,
                'created_at' => $log->created_at ?? now(),
                'updated_at' => $log->updated_at ?? now(),
            ]);
        }

        // Check if there are any students with leave date not yet in history
        $studentsWithLeave = DB::table('students')
            ->whereNotNull('date_of_leaving')
            ->get();

        foreach ($studentsWithLeave as $s) {
            $hasLeft = DB::table('student_status_history')
                ->where('student_id', $s->id)
                ->where('event_type', 'left')
                ->where('effective_date', $s->date_of_leaving)
                ->exists();

            if (!$hasLeft) {
                $reg = DB::table('student_registration_details')->where('student_id', $s->id)->first();
                DB::table('student_status_history')->insert([
                    'student_id' => $s->id,
                    'event_type' => 'left',
                    'effective_date' => $s->date_of_leaving,
                    'leaving_reason' => $reg->leaving_reason ?? null,
                    'leaving_category' => $reg->leaving_category ?? null,
                    'user_id' => null,
                    'actor_id' => null,
                    'metadata' => json_encode(['source' => 'legacy_reconstruction']),
                    'created_at' => $s->updated_at ?? now(),
                    'updated_at' => $s->updated_at ?? now(),
                ]);
            }

            if (!empty($s->date_of_readmission)) {
                $hasReadmit = DB::table('student_status_history')
                    ->where('student_id', $s->id)
                    ->where('event_type', 'readmitted')
                    ->where('effective_date', $s->date_of_readmission)
                    ->exists();

                if (!$hasReadmit) {
                    DB::table('student_status_history')->insert([
                        'student_id' => $s->id,
                        'event_type' => 'readmitted',
                        'effective_date' => $s->date_of_readmission,
                        'leaving_reason' => null,
                        'leaving_category' => null,
                        'user_id' => null,
                        'actor_id' => null,
                        'metadata' => json_encode(['source' => 'legacy_reconstruction']),
                        'created_at' => $s->updated_at ?? now(),
                        'updated_at' => $s->updated_at ?? now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_status_history');
    }
};
