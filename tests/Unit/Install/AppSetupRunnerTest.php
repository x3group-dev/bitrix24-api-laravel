<?php

namespace X3Group\Bitrix24\Tests\Unit\Install;

use Bitrix24\SDK\Services\ServiceBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use X3Group\Bitrix24\Application\Install\AppSetupRunner;
use X3Group\Bitrix24\Tests\Support\Fakes\RecordingInstaller;

class AppSetupRunnerTest extends TestCase
{
    private function sb(): ServiceBuilder { return $this->createStub(ServiceBuilder::class); }

    public function test_runs_installers_in_config_order(): void
    {
        $journal = [];
        $map = ['A'=>new RecordingInstaller($journal,'A'),'B'=>new RecordingInstaller($journal,'B'),'C'=>new RecordingInstaller($journal,'C')];
        (new AppSetupRunner(new NullLogger(), fn(string $c)=>$map[$c]))->run($this->sb(), ['A','B','C']);
        $this->assertSame(['A','B','C'], $journal);
    }
    public function test_empty_config_is_noop(): void
    {
        $this->expectNotToPerformAssertions();
        (new AppSetupRunner(new NullLogger(), fn(string $c)=>null))->run($this->sb(), []);
    }
    public function test_rejects_class_not_implementing_interface(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AppSetupRunner(new NullLogger(), fn(string $c)=>new \stdClass()))->run($this->sb(), ['X']);
    }
    public function test_logs_and_rethrows_when_installer_throws(): void
    {
        $journal = [];
        $map = ['A'=>new RecordingInstaller($journal,'A'),'B'=>new RecordingInstaller($journal,'B',new \RuntimeException('boom')),'C'=>new RecordingInstaller($journal,'C')];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->anything(), $this->callback(fn($ctx)=>($ctx['installer']??null)==='B'));
        try { (new AppSetupRunner($logger, fn(string $c)=>$map[$c]))->run($this->sb(), ['A','B','C']); $this->fail('expected'); }
        catch (\RuntimeException $e) { $this->assertSame('boom',$e->getMessage()); }
        $this->assertSame(['A','B'], $journal);
    }
}
