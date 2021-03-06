<?php

namespace AC\FiendishBundle\Tests;

abstract class DaemonsTestCase extends FiendishTestCase
{
    abstract protected function getDaemonClass();

    private function assertProcessLivesAndOutputs($proc, $expectedOutput)
    {
        $supervisor = parent::getSupervisorClient();
        $ok = false;
        for ($i = 1; $i < 30; ++$i) {
            usleep(1000 * 100); // 100 milliseconds
            $procInfo = $supervisor->getProcessInfo($proc->getFullProcName());
            if (!is_null($procInfo)) {
                $output = $supervisor->tailProcessStdoutLog(
                    $proc->getFullProcName(),
                    0,
                    5000
                )[0];

                if (
                    in_array($procInfo["statename"], ["RUNNING", "STARTING"]) &&
                    strpos($output, $expectedOutput) !== FALSE
                ) {
                    $ok = true;
                    break;
                }
            }
        }

        $this->assertTrue(
            $ok,
            "Process " . $proc->getProcName() . " lives and outputs $expectedOutput"
        );
    }

    private function getProcessPids($proc)
    {
        $pids = [];
        for ($i = 1; $i < 30; ++$i) {
            usleep(1000 * 100); // 100 milliseconds
            // Get within the loop in case supervisor restarts
            $supervisor = parent::getSupervisorClient();
            try {
                $procInfo = $supervisor->getProcessInfo($proc->getFullProcName());
                if (!is_null($procInfo)) {
                    $pid = (int)($procInfo['pid']);
                    if ($pid > 0) {
                        $pids[$pid] = true;
                    }
                }
            } catch (\Exception $e) {
                // Supervisor throws an exception if the proc name isn't valid.
            }
        }
        return array_keys($pids);
    }

    private function assertGroupSize($grp, $size)
    {
        $supervisor = parent::getSupervisorClient();

        $ok = false;
        for ($i = 1; $i < 50; ++$i) {
            usleep(1000 * 100); // 100 milliseconds
            $count = 0;
            foreach ($supervisor->getAllProcessInfo() as $sp) {
                if ($sp["group"] == $grp->getName()) {
                    ++$count;
                }
            }
            if ($count === $size) {
                $ok = true;
                break;
            }
        }
        $this->assertTrue($ok, "Group " . $grp->getName() . " becomes size $size");
    }

    public function testDaemonControl()
    {
        $this->requiresMaster();

        $grp = $this->getGroup();
        $proc = $grp->newProcess(
            "simple",
            $this->getDaemonClass(),
            ["content" => "narf"]
        );
        $this->assertGroupSize($grp, 0);
        $grp->applyChanges();
        $this->assertGroupSize($grp, 1);
        $this->assertProcessLivesAndOutputs($proc, "narfomatic");
        $this->assertEquals(1, count($this->getProcessPids($proc)));

        $proc2 = $grp->newProcess(
            "simple2",
            $this->getDaemonClass(),
            ["content" => "bork"]
        );
        $this->assertGroupSize($grp, 1);
        $grp->applyChanges();
        $this->assertGroupSize($grp, 2);
        $this->assertProcessLivesAndOutputs($proc2, "borkomatic");

        $grp->removeProcess($proc);
        $this->assertGroupSize($grp, 2);
        $grp->applyChanges();
        $this->assertGroupSize($grp, 1);

        $grp->removeProcess($proc2);
        $this->assertGroupSize($grp, 1);
        $grp->applyChanges();
        $this->assertGroupSize($grp, 0);
    }

    public function testProcessAutoRestart()
    {
        $this->requiresMaster();

        $grp = $this->getGroup();
        $proc = $grp->newProcess(
            "simple",
            $this->getDaemonClass(),
            ["content" => "die"]
        );
        $grp->applyChanges();

        $pidsBefore = $this->getProcessPids($proc);
        $this->assertEquals(1, count($pidsBefore));
        posix_kill($pidsBefore[0], 9);
        usleep(1000 * 100);
        $pidsAfter = $this->getProcessPids($proc);
        $this->assertEquals(1, count($pidsAfter));
        $this->assertNotEquals($pidsBefore[0], $pidsAfter[0]); // Proc restarted
    }

    public function testMasterRestart()
    {
        $this->requiresMaster();

        $grp = $this->getGroup();
        $proc = $grp->newProcess(
            "simple",
            $this->getDaemonClass(),
            ["content" => "narf"]
        );
        $grp->applyChanges();
        $pidsBefore = $this->getProcessPids($proc);
        $this->assertEquals(1, count($pidsBefore));

        // Kill master and wait for it to come back
        $supervisor = parent::getSupervisorClient();
        $masterInfo = $supervisor->getProcessInfo("testfiendish_master");
        $this->assertGreaterThan(0, $masterInfo['pid']);
        posix_kill($masterInfo['pid'], 9);
        sleep(2);
        $masterInfo2 = $supervisor->getProcessInfo("testfiendish_master");
        $this->assertGreaterThan(0, $masterInfo2['pid']);
        $this->assertNotEquals($masterInfo['pid'], $masterInfo2['pid']);

        // Assert that TestDaemon did not have to restart
        $pidsAfter = $this->getProcessPids($proc);
        $this->assertEquals($pidsBefore, $pidsAfter);
    }

    public function testSupervisorRestart()
    {
        $this->requiresMaster();

        $grp = $this->getGroup();
        $proc = $grp->newProcess(
            "simple",
            $this->getDaemonClass(),
            ["content" => "narf"]
        );
        $grp->applyChanges();
        $pidsBefore = $this->getProcessPids($proc);
        $this->assertEquals(1, count($pidsBefore));
        `/etc/init.d/supervisor restart`;
        sleep(3);

        // Restart master daemon.
        // Normally it would start by itself, but we have disabled autostart
        // in the test environment's supervisor config.
        $supervisor = parent::getSupervisorClient();
        $supervisor->startProcess("testfiendish_master");
        sleep(3);
        $masterInfo = $supervisor->getProcessInfo("testfiendish_master");
        $this->assertGreaterThan(0, $masterInfo['pid']);

        // Assert that daemon was restarted as well
        $pidsAfter = $this->getProcessPids($proc);
        $this->assertEquals(1, count($pidsAfter));
        $this->assertNotEquals($pidsBefore[0], $pidsAfter[0]);
    }

    public function testHeartbeatAbsenceCausesAutoRestart()
    {
        $this->requiresMaster();

        $grp = $this->getGroup();
        $proc = $grp->newProcess(
            "simple",
            $this->getDaemonClass(),
            ["content" => "vampire"] // Prevents TestDaemon heartbeats
        );
        $grp->applyChanges();

        $pidsBefore = $this->getProcessPids($proc);
        $this->assertEquals(1, count($pidsBefore));
        sleep(10); // Enough time for master daemon to notice lack of heartbeats
        $pidsAfter = $this->getProcessPids($proc);
        $this->assertNotContains($pidsBefore[0], $pidsAfter); // Proc restarted
    }

    public function testHeartbeatPresencePreventsAutoRestart()
    {
        $this->requiresMaster();

        $grp = $this->getGroup();
        $proc = $grp->newProcess(
            "simple",
            $this->getDaemonClass(),
            ["content" => "human"]
        );
        $grp->applyChanges();

        $pidsBefore = $this->getProcessPids($proc);
        $this->assertEquals(1, count($pidsBefore));
        sleep(10);
        $pidsAfter = $this->getProcessPids($proc);
        $this->assertEquals(1, count($pidsAfter));
        $this->assertEquals($pidsBefore[0], $pidsAfter[0]); // Proc not restarted
    }
}
