<?php

namespace Tests\Feature;

use App\User;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Project\Http\Controllers\ProjectController;
use Modules\Project\Utils\ProjectUtil;
use Tests\TestCase;

class ProjectUpdateNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated Project update tests.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('pjt_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id');
            $table->string('name');
            $table->integer('contact_id')->nullable();
            $table->string('status');
            $table->integer('lead_id');
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->text('description')->nullable();
            $table->integer('created_by');
            $table->text('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('pjt_project_members', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('project_id');
            $table->integer('user_id');
        });

        Schema::create('categorizables', function (Blueprint $table) {
            $table->integer('category_id');
            $table->string('categorizable_type');
            $table->integer('categorizable_id');
        });
    }

    public function test_project_update_succeeds_when_a_new_member_receives_a_project_link(): void
    {
        DB::table('pjt_projects')->insert([
            'id' => 100,
            'business_id' => 7,
            'name' => 'Project to update',
            'contact_id' => null,
            'status' => 'in_progress',
            'lead_id' => 1,
            'start_date' => null,
            'end_date' => null,
            'description' => null,
            'created_by' => 1,
            'settings' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = new class extends User
        {
            public function can($ability, $arguments = [])
            {
                return $ability === 'superadmin';
            }
        };
        $actor->forceFill([
            'id' => 1,
            'surname' => 'Admin',
            'first_name' => 'Project',
            'last_name' => '',
        ]);
        auth()->setUser($actor);

        $request = Request::create('/project/project/100', 'PUT', [
            'name' => 'Project to update',
            'description' => null,
            'contact_id' => null,
            'status' => 'in_progress',
            'lead_id' => 1,
            'start_date' => null,
            'end_date' => null,
            'user_id' => [2],
            'category_id' => [],
        ]);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('user.business_id', 7);
        $request->setUserResolver(function () use ($actor) {
            return $actor;
        });
        app()->instance('request', $request);
        Event::fake();

        $expectedProjectUrl = route('project.show', ['project' => 100]);
        $projectUtil = Mockery::mock(ProjectUtil::class);
        $projectUtil->shouldReceive('notifyUsersAboutAssignedProject')
            ->once()
            ->with(
                Mockery::on(function ($memberIds) {
                    return array_values($memberIds) === [2];
                }),
                Mockery::on(function ($project) use ($expectedProjectUrl) {
                    return $project->link === $expectedProjectUrl;
                })
            );

        $controller = new ProjectController(
            Mockery::mock(Util::class),
            $projectUtil,
            Mockery::mock(ModuleUtil::class)
        );

        $result = $controller->update($request, 100);

        $this->assertTrue($result['success']);
    }
}
