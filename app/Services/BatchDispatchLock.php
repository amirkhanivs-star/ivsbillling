<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** MySQL connection-owned locks are released by the server if a worker dies. */
class BatchDispatchLock
{
    private static array $heldInProcess = [];
    private $connection;
    private $testLock;
    private string $name = '';
    private bool $acquired = false;

    public function acquire(string $batchId): bool
    {
        $this->connection = DB::connection();
        $this->name = 'iqra-dispatch:'.substr(hash('sha256', $batchId), 0, 48);
        if ($this->connection->getDriverName() === 'sqlite' && app()->environment('testing')) {
            $this->testLock = Cache::store('array')->lock($this->name, 360);
            $this->acquired = (bool) $this->testLock->get();
            return $this->acquired;
        }

        if (!empty(self::$heldInProcess[$this->name])) {
            return false;
        }

        // Force the write connection: named locks must not run on a read replica.
        $this->acquired = (int) ($this->connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$this->name], false)->acquired ?? 0) === 1;
        if ($this->acquired) {
            self::$heldInProcess[$this->name] = true;
        }

        return $this->acquired;
    }

    public function release(): void
    {
        if ($this->name !== '') {
            unset(self::$heldInProcess[$this->name]);
        }

        if ($this->testLock) {
            $this->testLock->release();
        } else {
            $this->connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->name], false);
        }
        $this->acquired = false;
    }

    public function __destruct()
    {
        if ($this->acquired && $this->name !== '' && app()->environment('testing')) {
            unset(self::$heldInProcess[$this->name]);
        }
    }
}
