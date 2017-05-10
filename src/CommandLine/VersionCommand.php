<?php

namespace Worklog\CommandLine;

/**
 * VersionCommand
 * Created by PhpStorm.
 * User: allenmccabe
 * Date: 5/10/17
 * Time: 10:50 AM
 */

class VersionCommand extends Command
{
    public $command_name;

    public static $description = 'Display application version';
    public static $options = [
//        'l' => ['req' => null, 'description' => 'Show all available information'],
//        's' => ['req' => null, 'description' => 'Show less information']
    ];
    public static $arguments = [ 'subcommand', 'version' ];
//    public static $usage = '%s [-ls] [opt1]';
    public static $menu = true;

    protected static $exception_strings = [
        'detached_head' => 'Your local repository is not synced with a particular version',
        'invalid_tag' => 'The supplied version does not exist'
    ];


    public function init()
    {
        if (! $this->initialized()) {
            $this->registerSubcommand('check');
            $this->registerSubcommand('switch');

            parent::init();
        }
    }

    public function run()
    {
        parent::run();

        BinaryCommand::collect_output();

        if ($subcommand = $this->argument('subcommand')) {
            return $this->runSubcommand($subcommand);
        }

        if ($tag = $this->gitTagFor('HEAD')) {
            return $tag;
        } else {
            throw new \Exception(static::$exception_strings['detached_head']);
        }
    }


    /**
     * @return mixed
     * @throws \Exception
     */
    protected function _check($internally_invoked = false, $tag = null)
    {
        Command::call(GitCommand::class, 'fetch -q');

        $tags = Command::call(GitCommand::class, 'tag');
        $args = $this->arguments();

        // Current version
        if (is_null($tag)) {
            if (isset($args[1])) {
                $tag = $args[1];

                if (! in_array($tag, $tags)) {
                    throw new \Exception(static::$exception_strings['invalid_tag']);
                }
            } else {
                $tag = $this->gitTagFor('HEAD');
            }
        }

        $latest_tag = $tag;
        $latest_result = null;

        foreach ($tags as $key => $_tag) {
            $comp = version_compare($tag, $_tag);

            if ($comp > -1 || $comp > $latest_result) {
                $latest_tag = $_tag;
                $latest_result = $comp;
            }
        }

        if ($internally_invoked) {
            return [$latest_tag, $latest_result];
        } else {
            if ($latest_result) {
                return sprintf('Later version %s available', $latest_tag);
            } else {
                return 'You have the most up to date version';
            }
        }
    }

    /**
     * @param bool $internally_invoked
     * @param null $new
     * @return mixed
     */
    protected function _switch($internally_invoked = false, $new = null)
    {
        $switched_to = false;
        $force_newest = is_null($new);

        if ($force_newest) {
            $new = $this->getData('version');
        } else {
            list($new, $diff) = $this->_check(true, $new);
        }

        debug(compact('new', 'diff'), 'cyan');


        if ($new) {
            if ($hash = $this->gitHashForTag($new)) {
                $switched_to = $new;
                Command::call(GitCommand::class, 'fetch -q');
                Command::call(GitCommand::class, sprintf('checkout %s -q', $hash));
                Command::call(ComposerCommand::class, 'install');
            }
        }

        if ($internally_invoked) {
            return $switched_to;
        } else {
            if ($switched_to) {
                return sprintf('Switched to version %s', $switched_to);
            } else {
                return 'You have the most up to date version';
            }
        }
    }

    /**
     * Get commit hash for the specified revision
     * @param string $revision_specifier
     * @return mixed
     */
    private function gitHashFor($revision_specifier = 'HEAD')
    {
        return unwrap(Command::call(GitCommand::class, sprintf('rev-parse %s', $revision_specifier)));
    }

    /**
     * Get commit hash for the specified tag/version
     * @param $tag
     * @return mixed
     */
    private function gitHashForTag($tag)
    {
        return unwrap(Command::call(GitCommand::class, sprintf('rev-list -n 1 %s', $tag)));;
    }

    /**
     * Get a tag associated with the specified revision
     * @param string $revision_specifier
     * @param bool $skip_rev_parse
     * @return mixed
     */
    private function gitTagFor($revision_specifier = 'HEAD', $skip_rev_parse = false)
    {
        if ($skip_rev_parse) {
            $commitHash = $revision_specifier;
        } else {
            $commitHash = $this->gitHashFor($revision_specifier);
        }

        $result = Command::call(
            GitCommand::class,
            sprintf(
                "show-ref --tags -d | grep ^%s | sed -e 's,.* refs/tags/,,' -e 's/\\^{}//'",
                $commitHash
            )
        );

        if ($result) {
            return unwrap($result);
        }

        return false;
    }

    /**
     * @param bool $return_diff
     * @return array|null
     */
    private function gitLatestVersion($return_diff = false)
    {
        $tags = Command::call(GitCommand::class, 'tag');

        $latest_tag = null;
        $latest_result = null;

        foreach ($tags as $key => $_tag) {

            if (is_null($latest_tag)) {
                $latest_tag = $_tag;
            }

            if (($result = strcmp($_tag, $latest_tag)) > 0) {
                $latest_tag = $_tag;
                $latest_result = $result;
            }
        }

        if ($return_diff) {
            return [ $latest_tag, $latest_result ];
        } else {
            return $latest_tag;
        }
    }
}