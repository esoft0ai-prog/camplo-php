<?php

namespace Tests\Unit;

use App\Support\Sql;
use PHPUnit\Framework\TestCase;

/** The Postgres → MySQL fragment translator (the MySQL side is exercised end to end by running the suite on MySQL). */
class SqlTest extends TestCase
{
    public function test_filtered_aggregates(): void
    {
        $this->assertSame("coalesce(sum(case when status = 'responded' then 1 else 0 end), 0) as r", Sql::toMysql("count(*) filter (where status = 'responded') as r"));
        $this->assertSame('avg(case when responded_at is not null then x * 1000 end)', Sql::toMysql('avg(x * 1000) filter (where responded_at is not null)'));
        $this->assertSame('count(*) as n', Sql::toMysql('count(*) as n'));
    }

    public function test_time_arithmetic(): void
    {
        $this->assertSame('(timestampdiff(microsecond, received_at, coalesce(responded_at, now(3))) / 1000000) > ? * 60',
            Sql::toMysql('extract(epoch from (coalesce(responded_at, now()) - received_at)) > ?::int * 60'));
        $this->assertSame('received_at < (now(3) - interval (case when vip then ? else ? end) minute)',
            Sql::toMysql("received_at < now() - (case when vip then ?::int else ?::int end) * interval '1 minute'"));
        $this->assertSame('responded_at >= now(3) - interval 1 day', Sql::toMysql("responded_at >= now() - interval '1 day'"));
    }

    public function test_dates_casts_and_booleans(): void
    {
        $this->assertSame("date_format(received_at, '%Y-%m-%d') as day", Sql::toMysql("to_char(received_at at time zone 'UTC', 'YYYY-MM-DD') as day"));
        $this->assertSame('dayofweek(l.received_at) in (1, 7)', Sql::toMysql('extract(isodow from l.received_at) >= 6'));
        $this->assertSame('max(e.is_milestone)', Sql::toMysql('bool_or(e.is_milestone)'));
        $this->assertSame('id like ?', Sql::toMysql('id::text like ?'));
    }
}
