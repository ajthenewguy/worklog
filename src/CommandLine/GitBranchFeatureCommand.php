<?php

namespace Worklog\CommandLine;

use Worklog\Services\Git;

class GitBranchFeatureCommand extends GitBranchCommand
{
	public static $description = 'Create a new feature branch';

    public static $options = [];

    public static $arguments = [ 'name' ];

    public static $menu = false;
    

    public function run()
    {
        $this->init();

        $this->branch = coalesce($this->argument('name'), $this->getData('name'));
        $this->type = Git::BRANCH_TYPE_FEATURE;

        if ($this->branch) {
            return $this->branch();
        } elseif (Input::confirm('Are you sure you want to merge the feature and close the branch?')) {
            $this->commit();
            $this->close();

            return sprintf("Branch %s closed", $this->branch);
        } else {
            return 'Close aborted';
        }
    }
}