<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{GoogleSheetsService, OtpService, QueueService};
use AfricaGates\Support\Maintenance;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

final class GoogleSheetsQueueTest extends TestCase
{
    /** @dataProvider deliveryOutcomes */
    public function test_the_maintenance_handler_retries_unaccepted_deliveries(int $code, string $body, string $status): void
    {
        $calls = 0;
        GoogleSheetsService::$transport = static function() use($code,$body,&$calls){$calls++;return ['code'=>$code,'body'=>$body];};
        try {
            $container = new \DI\Container([
                GoogleSheetsService::class=>new GoogleSheetsService('https://example.org/exec'),
                OtpService::class=>new OtpService([]),
            ]);
            $id=(new QueueService())->push('vote.sheets_push', ['nominee_id'=>1]);
            (new Maintenance($container))->run('queue');
            $row=DB::table('gates_jobs')->find($id);
            $this->assertSame(1,$calls);
            $this->assertSame($status,$row->status);
            $this->assertSame(1,(int)$row->attempts);
            if($status==='pending'){
                $this->assertNotEmpty($row->last_error);
                $this->assertGreaterThan(date('Y-m-d H:i:s'),$row->run_after);
            } else $this->assertNull($row->last_error);
        } finally {GoogleSheetsService::$transport=null;}
    }

    public static function deliveryOutcomes(): array
    {
        return [
            'unavailable'=>[503,'unavailable','pending'],
            'refused under HTTP 200'=>[200,'{"success":false}','pending'],
            'invalid response'=>[200,'<html>error</html>','pending'],
            'accepted'=>[200,'{"success":true}','done'],
        ];
    }
}
