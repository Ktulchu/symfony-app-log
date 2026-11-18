<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

$kernel = new Fixture\Kernel('test', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
$audit = $kernel->getContainer()->get(\Ktulchu\AppLog\AuditSubscriber::class);
$command = new \Symfony\Component\Console\Command\Command('app:audit-smoke');
$input = new \Symfony\Component\Console\Input\ArrayInput([]);
$output = new \Symfony\Component\Console\Output\BufferedOutput();
$kernel->getContainer()->get('event_dispatcher')->dispatch(
    new \Symfony\Component\Console\Event\ConsoleCommandEvent($command, $input, $output),
    \Symfony\Component\Console\ConsoleEvents::COMMAND
);

(new Doctrine\ORM\Tools\SchemaTool($em))->createSchema([
    $em->getClassMetadata(Fixture\Entity\ExampleMySql::class),
]);

$record = new Fixture\Entity\ExampleMySql();
$record->document = '{"a":1,"b":2}';
$em->wrapInTransaction(fn () => $em->persist($record));
$audit->withSourceType('queue:FixtureJob', function () use ($em, $record): void {
    $em->wrapInTransaction(function () use ($record): void {
        $record->title = 'updated';
        $record->document = '{"a":1,"b":[2,3]}';
    });
});
$em->wrapInTransaction(fn () => $em->remove($record));

$rows = $db->fetchAllAssociative('SELECT operation, old_values, new_values, source_type FROM app_log ORDER BY id');
if (array_column($rows, 'source_type') !== ['console:app:audit-smoke', 'queue:FixtureJob', 'console:app:audit-smoke']) {
    throw new RuntimeException('Incorrect source_type in MySQL Symfony consumer');
}
if (array_column($rows, 'operation') !== ['insert', 'update', 'delete']) {
    throw new RuntimeException('Expected exactly insert/update/delete');
}
$updated = json_decode($rows[1]['new_values'], true, flags: JSON_THROW_ON_ERROR);
if ($updated['document']['data']['value']['b'] !== [2, 3] ||
    $updated['title']['data']['value'] !== 'updated') {
    throw new RuntimeException('MySQL JSON values corrupted');
}
if (isset(json_decode($rows[0]['new_values'], true)['secret'])) {
    throw new RuntimeException('Excluded secret leaked');
}
if ((int)$db->fetchOne('SELECT count(*) FROM mysql_example') !== 0) {
    throw new RuntimeException('Entity was not deleted');
}
echo "Symfony/MySQL composer installation + migration + INSERT/UPDATE/DELETE: OK\n";
$kernel->shutdown();
