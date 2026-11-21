<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Shared\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Feature\ApiTestCase;

class SequenceTest extends ApiTestCase
{
    public function test_sequence_formats_and_increments(): void
    {
        $this->assertSame('P-000001', SequenceGenerator::next('employee_no', 'P-%06d'));
        $this->assertSame('P-000002', SequenceGenerator::next('employee_no', 'P-%06d'));
    }

    public function test_sequences_are_independent_by_key(): void
    {
        SequenceGenerator::next('employee_no', 'P-%06d');
        SequenceGenerator::next('employee_no', 'P-%06d');

        $this->assertSame('S-0001', SequenceGenerator::next('contract_no', 'S-%04d'));
    }

    public function test_rolled_back_transaction_does_not_leave_a_visible_record(): void
    {
        SequenceGenerator::next('employee_no', 'P-%06d');

        try {
            DB::transaction(function () {
                SequenceGenerator::next('employee_no', 'P-%06d');
                throw new RuntimeException('iş işlemi başarısız');
            });
        } catch (RuntimeException) {
            // Beklenen: dış transaction geri alındı.
        }

        $this->assertSame('P-000002', SequenceGenerator::next('employee_no', 'P-%06d'));
    }

    public function test_format_without_a_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SequenceGenerator::next('employee_no', 'P-SABIT');
    }

    public function test_parallel_workers_get_unique_numbers(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralel kilit testi yalnızca MySQL üzerinde anlamlıdır.');
        }

        // Çalışanlar testin açık transaction'ını göremez; kendi satırlarını commit ederler.
        $key = 'concurrency_'.Str::uuid();
        $database = $connection->getDatabaseName();
        self::assertSafeTestDatabase($connection->getDriverName(), $database);

        config(['database.connections.hr_test_cleanup' => config('database.connections.mysql')]);

        try {
            $workers = [];
            for ($i = 0; $i < 4; $i++) {
                $worker = new Process(
                    [PHP_BINARY, base_path('tests/Concurrency/sequence_worker.php'), $key, '50'],
                    base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database],
                    null,
                    120,
                );
                $worker->start();
                $workers[] = $worker;
            }

            $numbers = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                array_push($numbers, ...preg_split('/\R/', trim($worker->getOutput())));
            }

            $this->assertCount(200, $numbers);
            $this->assertCount(200, array_unique($numbers));
        } finally {
            DB::connection('hr_test_cleanup')->table('hr_sequences')->where('key', $key)->delete();
            DB::purge('hr_test_cleanup');
        }
    }
}
