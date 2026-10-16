<?php

declare(strict_types=1);

namespace App\Tests\Service\Update;

use App\Service\Update\FileOwner;
use App\Service\Update\UpdateJournal;
use PHPUnit\Framework\TestCase;

final class FileOwnerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/aggregate-file-owner-'.bin2hex(random_bytes(6));
        mkdir($this->project.'/var/updates', 0775, true);
    }

    protected function tearDown(): void
    {
        chmod($this->project.'/var/updates', 0775);
        @rmdir($this->project.'/var/updates/uploads');
        rmdir($this->project.'/var/updates');
        rmdir($this->project.'/var');
        rmdir($this->project);
    }

    public function testAFolderOfAnotherUserIsNamedWithTheCommandThatGivesItBack(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs root to give the folder to another user.');
        }
        chown($this->project.'/var/updates', 'nobody');

        self::assertSame(
            'var/updates belongs to nobody, but this runs as root. A command run as nobody usually created it. As root, give it back with chown -R root var/updates, or delete it so it is created again.',
            FileOwner::explain($this->project.'/var/updates/uploads/abc', $this->project),
        );
    }

    public function testAFolderOfThisUserPointsAtItsPermissions(): void
    {
        self::assertSame(FileOwner::current().' cannot write var/updates. Check its permissions.', FileOwner::explain($this->project.'/var/updates/uploads', $this->project));
        self::assertSame('var/updates/uploads', FileOwner::relative($this->project.'/var/updates/uploads', $this->project));
        self::assertSame('/elsewhere/file', FileOwner::relative('/elsewhere/file', $this->project));
    }

    public function testTheUpdateJournalSaysWhichFolderIsInTheWay(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root can write any folder.');
        }
        chmod($this->project.'/var/updates', 0555);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The update folder var/updates/uploads could not be created: '.FileOwner::current().' cannot write var/updates.');
        (new UpdateJournal($this->project))->ensureDirectory('uploads');
    }
}
