<?php
namespace Worklog\CommandLine;

/**
 * ViewCacheCommand
 * Lists options this script takes (CLI)
 */
class ViewCacheCommand extends Command
{
    public $command_name;

    public static $description = 'Display local cache contents';
    public static $options = [
        'k' => ['req' => true, 'description' => 'Use jq to get the specified cached item property'],
        't' => ['req' => null, 'description' => 'Query by tags']
    ];
    public static $arguments = [ 'query' ];
    // public static $usage = '[] %s';
    public static $menu = false;

    public function run()
    {
        $output = "No cached data.";

        if ($this->option('t')) {
            $tags = (array) $this->getData('query');
        } elseif ($query = $this->getData('query')) {
            // wlog view-cache -k data ea2b2676c28c0db26d39331a336c6b92.json
            if ($keypath = $this->option('k')) {
                $Cache = App()->Cache();
                $file_type = $Cache->file_type();
                $filepath = null;

                if ($file_type !== 'array') {
                    $filepath = $Cache->filename($query);
                }

                if (! is_null($filepath) && false === strpos($filepath, $Cache->path())) {
                    $filepath = $Cache->path().'/'.$filepath;
                }


                if (substr($keypath, 0, 1) !== '.') {
                    $keypath = '.'.$keypath;
                }

                if (! is_null($filepath)) {
                    $set_to = BinaryCommand::collect_output();
                    $Command = new BinaryCommand([
                        'cat',  $filepath, '|', 'jq', escapeshellarg($keypath)
                    ]);

                    if ($output = $Command->run()) {
                        if (is_array($output) && isset($output[0])) {
                            $output = array_map(function($line) {
                                $line = json_decode($line);
                                if (false !== ($_unserialized = @unserialize($line))) {
                                    $line = $_unserialized;
                                }
                                return $line;
                            }, $output);
                        }
                    }

                    BinaryCommand::collect_output($set_to);
                }

                return $output;
            }
        }
        
        $grid_data = $raw_cache_data = [];
        $registry = $this->App()->Cache()->registry();
        // $template = [ 40, 38, 40, 19, 21 ];
        $template = [ 40, 38, null, null, null ];

        if (count($registry)) {
            foreach ($registry as $cache_name => $path) {
                $raw_cache_data = $this->App()->Cache()->load($cache_name, true);

                if ($tags && ! array_intersect($tags, $raw_cache_data['tags'])) {
                    continue;
                }

                $data_out = '-';
                if (is_scalar($raw_cache_data['data'])) {
                    $data_out = $raw_cache_data['data'];
                } elseif (is_array($raw_cache_data['data'])) {
                    $data_out = print_r($raw_cache_data['data'], 1);
                } else {
                    $data_out = get_class($raw_cache_data['data']);
                }
                $data_out = preg_replace('/[\r\n\s]+/', ' ', $data_out);

                $grid_data[] = [
                    $raw_cache_data['name'],
                    basename($path),
                    $data_out,
                    date("Y-m-d H:i:s", $raw_cache_data['expiry']),
                    implode(', ', $raw_cache_data['tags'])
                ];
            }

            if (count($grid_data)) {
                $output = Output::data_grid([ 'Name', 'File', 'Data', 'Expiry', 'Tags' ], $grid_data/*, $template*/);
            }
        }

        return $output;
    }
}
