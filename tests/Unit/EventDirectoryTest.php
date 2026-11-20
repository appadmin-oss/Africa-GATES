<?php
declare(strict_types=1);
namespace Tests\Unit;
use Tests\TestCase;
use AfricaGates\Admin\Services\EventDirectory;
use Illuminate\Database\Capsule\Manager as DB;
final class EventDirectoryTest extends TestCase
{
    public function test_search_filters_and_pages_preserve_literal_search(): void
    {
        DB::table('gates_site_events')->delete();
        for ($i=1;$i<=23;$i++) DB::table('gates_site_events')->insert(['title'=>'Training '.$i,'slug'=>'training-'.$i,'event_date'=>'2027-01-01 10:00:00','status'=>'draft','location'=>'Lagos']);
        DB::table('gates_site_events')->insert(['title'=>'100% Community','slug'=>'community','event_date'=>'2027-01-01 10:00:00','status'=>'published','location'=>'Abuja']);
        $first=EventDirectory::page(['status'=>'draft','q'=>'Lagos']);
        $this->assertSame(23,$first['total']); $this->assertCount(20,$first['rows']);
        $this->assertStringContainsString('q=Lagos', $first['next']);
        $last=EventDirectory::page(['status'=>'draft','q'=>'Lagos','page'=>999]);
        $this->assertSame(2,$last['page']);$this->assertCount(3,$last['rows']);$this->assertNull($last['next']);
        $literal=EventDirectory::page(['q'=>'%']);$this->assertSame(1,$literal['total']);
        $this->assertSame('100% Community',$literal['rows'][0]['title']);
        $this->assertSame(0,EventDirectory::page(['q'=>'missing'])['total']);
    }
}
