<?php

/**
 * Kullanım: php tests/Concurrency/sequence_worker.php <sequence_key> <adet>
 * Ürettiği her numarayı ayrı satıra yazar. SequenceTest tarafından paralel başlatılır.
 */

use App\Modules\Hr\Shared\Sequences\SequenceGenerator;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = $app['db']->connection();
TestCase::assertSafeTestDatabase($connection->getDriverName(), $connection->getDatabaseName());

[, $key, $count] = $argv;

for ($i = 0; $i < (int) $count; $i++) {
    echo SequenceGenerator::next($key, 'C-%06d'), PHP_EOL;
}
