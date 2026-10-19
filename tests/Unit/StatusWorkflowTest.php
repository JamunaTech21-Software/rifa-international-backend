<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Applications\Services\StatusWorkflow;
use PHPUnit\Framework\TestCase;

final class StatusWorkflowTest extends TestCase
{
    private StatusWorkflow $workflow;

    protected function setUp(): void
    {
        $this->workflow = new StatusWorkflow();
    }

    public function test_forward_moves_may_skip_steps_but_not_go_back(): void
    {
        $this->assertSame(
            ['shortlisted', 'interview', 'medical', 'visa_processing', 'departed', 'on_hold', 'rejected'],
            $this->workflow->allowedNext('under_review', false),
        );
        $this->assertTrue($this->workflow->canMove('received', 'shortlisted', false));
        $this->assertFalse($this->workflow->canMove('medical', 'interview', false));
        $this->assertFalse($this->workflow->canMove('received', 'received', false));
    }

    public function test_on_hold_returns_to_any_open_status_or_rejected(): void
    {
        $this->assertSame(
            ['received', 'under_review', 'shortlisted', 'interview', 'medical', 'visa_processing', 'rejected'],
            $this->workflow->allowedNext('on_hold', false),
        );
        $this->assertFalse($this->workflow->canMove('on_hold', 'departed', false));
    }

    public function test_final_statuses_only_reopened_by_super_admin(): void
    {
        $this->assertSame([], $this->workflow->allowedNext('departed', false));
        $this->assertSame([], $this->workflow->allowedNext('rejected', false));
        $this->assertTrue($this->workflow->canMove('rejected', 'under_review', true));
        $this->assertFalse($this->workflow->canMove('rejected', 'departed', true));
    }
}
