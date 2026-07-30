<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Unit\DTO;

use Temporal\Workflow\ContinueAsNewOptions;
use Temporal\Workflow\ContinueAsNewVersioningBehavior;

class ContinueAsNewOptionsTestCase extends AbstractDTOMarshalling
{
    /**
     * @throws \ReflectionException
     */
    public function testMarshalling(): void
    {
        $dto = new ContinueAsNewOptions();

        $expected = [
            'WorkflowRunTimeout'  => 0,
            'TaskQueueName'       => 'default',
            'WorkflowTaskTimeout' => 0,
            'InitialVersioningBehavior' => ContinueAsNewVersioningBehavior::Unspecified->value,
        ];

        $this->assertSame($expected, $this->marshal($dto));
    }

    public function testInitialVersioningBehaviorIsConfiguredImmutably(): void
    {
        $original = ContinueAsNewOptions::new();
        $configured = $original->withInitialVersioningBehavior(
            ContinueAsNewVersioningBehavior::UseRampingVersion,
        );

        self::assertNotSame($original, $configured);
        self::assertSame(
            ContinueAsNewVersioningBehavior::Unspecified,
            $original->initialVersioningBehavior,
        );
        self::assertSame(
            ContinueAsNewVersioningBehavior::UseRampingVersion,
            $configured->initialVersioningBehavior,
        );
        self::assertSame(
            ContinueAsNewVersioningBehavior::UseRampingVersion->value,
            $this->marshal($configured)['InitialVersioningBehavior'],
        );
    }
}
