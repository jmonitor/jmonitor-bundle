<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMessageTest extends TestCase
{
    public function testAPasswordAndItsUserLeaveAnUrl(): void
    {
        $message = ErrorMessage::clean('Cannot reach mysql://app:s3cret@db:3306/x');

        self::assertSame('Cannot reach mysql://db:3306/x', $message);
        self::assertStringNotContainsString('s3cret', $message);
        self::assertStringNotContainsString('app', $message);
    }

    public function testAUserAloneLeavesAnUrl(): void
    {
        self::assertSame('Cannot reach redis://cache', ErrorMessage::clean('Cannot reach redis://default@cache'));
    }

    public function testAPasswordHoldingAnAtSignLeavesAsWell(): void
    {
        self::assertSame('mysql://db/x and mail admin@example.com', ErrorMessage::clean('mysql://app:p@ss@db/x and mail admin@example.com'));
    }

    public function testAMessageWithoutUrlIsUnchanged(): void
    {
        self::assertSame('Access denied for user root@localhost', ErrorMessage::clean('Access denied for user root@localhost'));
    }

    public function testTheCauseFollowsTheMessage(): void
    {
        $e = new \RuntimeException('SHOW GLOBAL STATUS is not accessible', 0, new \RuntimeException('Connection refused'));

        self::assertSame('SHOW GLOBAL STATUS is not accessible (Connection refused)', ErrorMessage::of($e));
        self::assertSame('Connection refused', ErrorMessage::of(new \RuntimeException('Connection refused')));
    }

    public function testTheCauseIsAddedOnlyOnce(): void
    {
        $same = new \RuntimeException('Connection refused', 0, new \RuntimeException('Connection refused'));
        $quoted = new \RuntimeException('Cannot reach the server: Connection refused', 0, new \RuntimeException('Connection refused'));

        self::assertSame('Connection refused', ErrorMessage::of($same));
        self::assertSame('Cannot reach the server: Connection refused', ErrorMessage::of($quoted));
    }

    public function testALongMessageIsCutToFiveHundredCharacters(): void
    {
        $message = ErrorMessage::clean(str_repeat('a', 498) . 'é' . str_repeat('b', 2501));

        self::assertSame(ErrorMessage::MAX_LENGTH, mb_strlen($message));
        self::assertStringEndsWith('…', $message);
        self::assertTrue(mb_check_encoding($message, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function secrets(): iterable
    {
        yield 'a slash in the password' => ['Cannot reach mysql://jmonitor:Zq/sl4sh@127.0.0.1:3306/db', 'Zq/sl4sh', '127.0.0.1:3306/db'];
        yield 'a question mark in the password' => ['Cannot reach mysql://jmonitor:Zq?qm4rk@127.0.0.1:3306/db', 'Zq?qm4rk', '127.0.0.1:3306/db'];
        yield 'a hash in the password' => ['Cannot reach mysql://jmonitor:Zq#h4sh@127.0.0.1:3306/db', 'Zq#h4sh', '127.0.0.1:3306/db'];
        yield 'an at sign in the password' => ['Cannot reach mysql://jmonitor:Zq@4t@127.0.0.1:3306/db', 'Zq@4t', '127.0.0.1:3306/db'];
        yield 'a space in a quoted url' => ['The "mysql://jmonitor:Zq sp4ce@127.0.0.1/db" DSN is invalid.', 'sp4ce', '127.0.0.1/db'];
        yield 'an auth parameter' => ['Cannot reach redis://127.0.0.1:6379?auth=s3cr3t', 's3cr3t', '127.0.0.1:6379'];
        yield 'the keys of a queue' => ['sqs://default/queue?access_key=AKIAEXAMPLE&secret_key=SeCr3t%2FKey&region=eu-west-1', 'AKIAEXAMPLE', 'region=eu-west-1'];
        yield 'the secret key of a queue' => ['sqs://default/queue?access_key=AKIAEXAMPLE&secret_key=SeCr3t%2FKey&region=eu-west-1', 'SeCr3t', 'region=eu-west-1'];
        yield 'a PDO password' => ['mysql:host=db;dbname=app;password=Zqs3cr3t', 'Zqs3cr3t', 'dbname=app'];
        yield 'a libpq password' => ['host=db password=Zqs3cr3t dbname=app', 'Zqs3cr3t', 'dbname=app'];
        yield 'a quoted libpq password' => ["connection failed: host=db user=app password='my s3cret' dbname=app", 's3cret', 'host=db user=app password=… dbname=app'];
        yield 'a quoted password in a DSN' => ['bad DSN "mysql:host=db;password=\'s3 cret\'"', 's3 cret', '"mysql:host=db;password=…"'];
        yield 'a double-quoted auth parameter' => ['Cannot reach redis://127.0.0.1:6379?auth="p w"&timeout=2', 'p w', '127.0.0.1:6379?auth=…&timeout=2'];
        yield 'an encoded slash without scheme' => ['//jmonitor:Zq%2Fsl4sh@127.0.0.1:3306/db', 'Zq%2Fsl4sh', '127.0.0.1:3306/db'];
        yield 'an option name Messenger copies' => ['Invalid option(s) "r3tTail" passed to the Redis Messenger transport.', 'r3tTail', 'passed to the Redis Messenger transport'];
        yield 'a scheme glued to a colon' => ['DSN:mysql://u:s3cr@h/db', 's3cr', 'h/db'];
        yield 'a scheme glued to a dot' => ['see.mysql://u:s3cr@h/db', 's3cr', 'h/db'];
        yield 'a scheme glued to a slash' => ['a/mysql://u:s3cr@h/db', 's3cr', 'h/db'];
        yield 'an at sign in an auth parameter' => ['Cannot reach redis://host?auth=p@ss', 'ss', 'host?auth='];
        yield 'an at sign in a quoted auth parameter' => ['Redis connection "redis://host?auth=p@ss" failed', 'ss', 'host?auth='];
        yield 'an unknown option in a DSN' => ['Unknown option found in DSN: [Tail0fKey]. Allowed options are [buffer_size, wait_time].', 'Tail0fKey', 'buffer_size'];
        yield 'the account of a denied user' => ["SQLSTATE[HY000] [1045] Access denied for user 'jmonitor'@'172.22.0.1' (using password: YES)", 'jmonitor', 'Access denied for user … (using password: YES)'];
        yield 'the client address of a denied user' => ["SQLSTATE[HY000] [1045] Access denied for user 'jmonitor'@'172.22.0.1' (using password: YES)", '172.22.0.1', 'Access denied for user … (using password: YES)'];
        yield 'the account denied a database' => ["SQLSTATE[HY000] [1044] Access denied for user 'jmonitor'@'%' to database 'mysql'", 'jmonitor', "Access denied for user … to database 'mysql'"];
        yield 'the account denied a table' => ["SQLSTATE[42000]: Syntax error or access violation: 1142 SELECT command denied to user 'jmonitor'@'172.22.0.1' for table 'events_statements_summary_by_digest'", 'jmonitor', "SELECT command denied to user … for table 'events_statements_summary_by_digest'"];
        yield 'a space in an unquoted url' => ['mysql://jmonitor:Zq sp4ce@127.0.0.1/db cannot be parsed', 'sp4ce', 'mysql://127.0.0.1/db cannot be parsed'];
        yield 'the user of an unquoted url with a space' => ['mysql://jmonitor:Zq sp4ce@127.0.0.1/db cannot be parsed', 'jmonitor', 'mysql://127.0.0.1/db cannot be parsed'];
        yield 'a tab in an unquoted url' => ["The DSN mysql://jmonitor:Zq\tt4b@127.0.0.1/db is wrong", 't4b', 'The DSN mysql://127.0.0.1/db is wrong'];
        yield 'a space and a slash in an unquoted url' => ['mysql://jmonitor:Zq /sl4sh@127.0.0.1/db', 'sl4sh', 'mysql://127.0.0.1/db'];
        yield 'an escaped quote in a quoted password' => ["connection failed: host=db password='a\\'b t4il' dbname=app", 't4il', 'host=db password=… dbname=app'];
        yield 'a doubled quote in a quoted password' => ["connection failed: host=db password='it''s s3cret' dbname=app", 's3cret', 'host=db password=… dbname=app'];
        yield 'an escaped quote in a double-quoted password' => ['bad parameters: password="a\\"b t4il" host=db', 't4il', 'password=… host=db'];
        yield 'a quoted password cut short' => ["connection failed: host=db password='my s3cr", 's3cr', 'host=db password=…'];
        yield 'the account of a PostgreSQL user' => ['SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: FATAL:  password authentication failed for user "jmonitor"', 'jmonitor', 'password authentication failed for user …'];
    }

    #[DataProvider('secrets')]
    public function testASecretNeverSurvives(string $message, string $secret, string $kept): void
    {
        $clean = ErrorMessage::clean($message);

        self::assertStringNotContainsString($secret, $clean);
        self::assertStringContainsString($kept, $clean);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ordinaryMessages(): iterable
    {
        yield 'a refused connection' => ['connection to server at "127.0.0.1", port 36999 failed: Connection refused'];
        yield 'an unknown database' => ["SQLSTATE[HY000] [1049] Unknown database 'app'"];
        yield 'a missing file' => ['SQLSTATE[HY000] [2002] No such file or directory'];
        yield 'a port, then an e-mail address' => ['Nothing answered at http://localhost:8080 after 2 s, tell ops@example.com'];
        yield 'a port and a path, then an e-mail address' => ['Nothing answered at http://localhost:2019/metrics after 2 s, tell ops@example.com'];
        yield 'an IPv6 address, then an e-mail address' => ['Nothing answered at http://[::1]:8080 after 2 s, tell ops@example.com'];
        yield 'a port and a colon, then an e-mail address' => ['Cannot reach tcp://db:3306: timeout, admin@example.com'];
        yield 'quoted values that are no secret' => ["host=db dbname='app' sslmode='require'"];
    }

    #[DataProvider('ordinaryMessages')]
    public function testAnOrdinaryMessageIsLeftAlone(string $message): void
    {
        self::assertSame($message, ErrorMessage::clean($message));
    }

    public function testAnAddressWithoutCredentialsKeepsItsHost(): void
    {
        self::assertSame('Nothing answered at http://localhost/server-status', ErrorMessage::clean('Nothing answered at http://localhost/server-status'));
    }

    public function testAMessageThatCannotBeCleanedIsNotShown(): void
    {
        $limit = ini_set('pcre.backtrack_limit', '1');
        $jit = ini_set('pcre.jit', '0');

        try {
            $message = ErrorMessage::clean('Cannot reach mysql://app:s3cret@db:3306/x');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }

        self::assertSame(ErrorMessage::UNREADABLE, $message);
    }

    public function testAMessageThatIsNotUtf8IsStillShown(): void
    {
        $message = ErrorMessage::clean("Connexion refus\xE9e vers mysql://app:s3cret@db/x");

        self::assertNotSame(ErrorMessage::UNREADABLE, $message);
        self::assertStringContainsString('Connexion refus', $message);
        self::assertStringContainsString('db/x', $message);
        self::assertStringNotContainsString('s3cret', $message);
    }

    public function testAVeryLongMessageIsCleanedWithinItsFirstCharacters(): void
    {
        $started = hrtime(true);
        $slow = ErrorMessage::clean(str_repeat('(//x', 50_000) . ' @');
        $leading = ErrorMessage::clean('mysql://app:s3cret@db/x ' . str_repeat('a', 200_000));
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertLessThan(1.0, $elapsed);
        self::assertSame(ErrorMessage::MAX_LENGTH, mb_strlen($slow));
        self::assertStringStartsWith('mysql://db/x ', $leading);
        self::assertStringNotContainsString('s3cret', $leading);
    }
}
