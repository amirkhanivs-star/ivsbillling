<?php

namespace Tests\Feature;

use App\Services\BatchDispatchLock;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class BatchDispatchLockTest extends TestCase
{
    public function test_mysql_lock_uses_the_same_write_connection_for_acquisition_and_release(): void
    {
        $connection = Mockery::mock();
        $name = 'iqra-dispatch:'.substr(hash('sha256', 'fictional-batch'), 0, 48);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->once()
            ->with('SELECT GET_LOCK(?, 0) AS acquired', [$name], false)
            ->andReturn((object) ['acquired' => 1]);
        $connection->shouldReceive('selectOne')->once()
            ->with('SELECT RELEASE_LOCK(?) AS released', [$name], false)
            ->andReturn((object) ['released' => 1]);

        $lock = new BatchDispatchLock();
        $this->assertTrue($lock->acquire('fictional-batch'));
        $lock->release();
    }

    public function test_mysql_contention_and_lock_errors_never_grant_ownership(): void
    {
        $connection = Mockery::mock();
        DB::shouldReceive('connection')->twice()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->twice()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->twice()->andReturn(
            (object) ['acquired' => 0], (object) ['acquired' => null],
        );
        $this->assertFalse((new BatchDispatchLock())->acquire('fictional-batch'));
        $this->assertFalse((new BatchDispatchLock())->acquire('fictional-batch'));
    }
}
