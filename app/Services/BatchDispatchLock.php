<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** MySQL connection-owned locks are released by the server if a worker dies. */
class BatchDispatchLock
{
    private $connection;
    private $testLock;
    private string $name;

    public function acquire(string $batchId): bool
    {
        $this->connection = DB::connection();
        $this->name = 'iqra-dispatch:'.substr(hash('sha256', $batchId), 0, 48);
        if ($this->connection->getDriverName() === 'sqlite' && app()->environment('testing')) {
            $this->testLock = Cache::store('array')->lock($this->name, 360);
            return $this->testLock->get();
        }

        // Force the write connection: named locks must not run on a read replica.
        return (int) $this->connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$this->name], false)->acquired === 1;
    }

    public function release(): void
    {
        if ($this->testLock) {
            $this->testLock->release();
        } else {
            $this->connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->name], false);
        }
    }
}
