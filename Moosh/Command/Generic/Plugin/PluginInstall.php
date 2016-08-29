<?php
/**
 * moosh - Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Kacper Golewski <k.golewski@gmail.com>
 */

namespace Moosh\Command\Generic\Plugin;
use Moosh\MooshCommand;

class PluginInstall extends MooshCommand
{
    public function __construct()
    {
        parent::__construct('install', 'plugin');

        $this->addArgument('plugin_name');
        $this->addArgument('moodle_version');
        $this->addArgument('plugin_version');
        
        $this->addOption('g|usegit', 'Use git repo');
        $this->addOption('u|updatedb', 'Update Moodle DB (Non interactive Install)');
    }

    public function execute()
    {
        global $CFG;

        require_once($CFG->libdir.'/adminlib.php');       // various admin-only functions
        require_once($CFG->libdir.'/upgradelib.php');     // general upgrade/install related functions
        require_once($CFG->libdir.'/environmentlib.php');
        require_once($CFG->dirroot.'/course/lib.php');

        $pluginfullname = $this->arguments[0];
        $moodleversion = $this->arguments[1];
        $pluginversion = $this->arguments[2];
        $pluginsfile = home_dir() . '/.moosh/plugins.json';

        $usegit = $this->expandedOptions['usegit'];
        $doupdate = $this->expandedOptions['updatedb'];
        
        $stat = @stat($pluginsfile);
        if(!$stat || time() - $stat['mtime'] > 60*60*24 || !$stat['size']) {
            die("plugins.json file not found or too old. Run moosh plugin-list to download newest plugins.json file\n");
        }

        $pluginsdata = file_get_contents($pluginsfile);
        $decodeddata = json_decode($pluginsdata);
        $downloadurl = NULL;

//todo: if usegit ... get_plugin_git
        if ($usegit) {
            $gitrepourl = $this->get_plugin_giturl($decodeddata, $pluginfullname, $moodleversion, $pluginversion);
        
            if(!$gitrepourl) {
                die("Couldn't find $pluginfullname $gitrepourl\n");
            }

            list($plugintype,$pluginname) = explode('_', $pluginfullname, 2);
            $fixedplugintypepath = $this->fix_plugintype_path($plugintype) . "/$pluginname";
            echo "git clone $gitrepourl $fixedplugintypepath\n";
            run_external_command("cd {$CFG->dirroot};git clone $gitrepourl $fixedplugintypepath");
            echo "cd $fixedplugintypepath\n";
            //run_external_command("cd $fixedplugintypepath");
            echo "git checkout -b local_{$moodleversion}_hacks origin/master\n";
            run_external_command("cd {$CFG->dirroot}/$fixedplugintypepath;git checkout -b local_{$moodleversion}_hacks origin/master");
            echo "cd {$CFG->dirroot}\n";
            //run_external_command("cd {$CFG->dirroot}");
            echo "git add $fixedplugintypepath/\n";
            run_external_command("cd {$CFG->dirroot};git add $fixedplugintypepath/");
            echo "git commit -m '$fixedplugintypepath (new)'\n";
            run_external_command("cd {$CFG->dirroot};git commit -m '$fixedplugintypepath (new)'");

        } else {

echo "Downloading...";
die;
            $downloadurl = $this->get_plugin_url($decodeddata, $pluginfullname, $moodleversion, $pluginversion);
        
            if (!$downloadurl) {
                die("Couldn't find $pluginfullname $moodleversion\n");
            }
        
            $split = explode('_', $this->arguments[0], 2);
            $tempdir = home_dir() . '/.moosh/moodleplugins/';
        
        //todo: map special types to full paths
        
            if (!file_exists($tempdir)) {
                mkdir($tempdir);
            }
        
            if (!fopen($tempdir . $split[1] . ".zip", 'w')) {
                echo "Failed to save plugin.\n";
                return;
            }
        
            try {
                file_put_contents($tempdir . $split[1] . ".zip", file_get_contents($downloadurl));
            } catch (Exception $e) {
                echo "Failed to download plugin. " . $e . "\n";
                return;
            }
        
            run_external_command("unzip -o " . $tempdir . $split[1] . ".zip -d " . home_dir() . "/.moosh/moodleplugins/");
            run_external_command("cp -r " . $tempdir . $split[1] . "/ " . $this->get_install_path($split[0], $moodleversion));
        }
        
        echo "Installing $pluginfullname $moodleversion\n";
        if ($doupdate) {
            echo "Installing... (Non interactive Moodle DB update)\n";
            upgrade_noncore(true);
        }

        echo "Done\n";
    }

    /**
     * @param $plugintype string - One word (no spaces) plugin type.
     * @return string - Correct path of plugin type.
     */
    private function fix_plugintype_path($plugintype) {
        // Up to date info can be found:
        // https://github.com/moodle/moodle/blob/master/lib/classes/component.php#L400-L476
        switch ($plugintype) {
            case 'availability':    return 'availability/condition';
            case 'assignfeedback':  return 'mod/assign/feedback';
            case 'assignsubmission':return 'mod/assign/submission';
            case 'block':           return 'blocks';
            case 'tool':            return 'admin/tool';
            case 'cache':           return 'cache/store';
            case 'booktool':        return 'mod/book/tool';
            case 'calendartype':    return 'calendar/type';
            case 'datafield':       return 'mod/data/field';
            case 'format':          return 'course/format';
            case 'editor':          return 'lib/editor';
            case 'tinymce':         return 'lib/editor/tinymce/plugins';
            case 'atto':            return 'lib/editor/atto/plugins';
            case 'logstore':        return 'admin/tool/log/store';
            case 'gradereport':     return 'grade/report';
            case 'gradeexport':     return 'grade/export';
            //case 'gradingform':     return '???';
            case 'profilefield':    return 'user/profile/field';
            case 'qformat':         return 'question/format';
            case 'quizaccess':      return 'mod/quiz/accessrule';
            case 'quiz':            return 'mod/quiz/report';
            case 'qtype':           return 'question/type';
            case 'qbehaviour':      return 'question/behaviour';
            default:                return $plugintype;

        }
    }
    /**
     * Get the relative path for a plugin given it's type
     * 
     * @param string $type
     *   The plugin type (example: 'auth', 'block')
     * @param string $moodleversion
     *   The version of moodle we are running (example: '1.9', '2.9')
     * @return string
     *   The installation path relative to dirroot (example: 'auth', 'blocks', 
     *   'course/format')
     */
    private function get_install_path($type, $moodleversion)
    {
        global $CFG;
        
        // Convert moodle version to a float for more acurate comparison
        if (!is_float($moodleversion)) {
            $moodleversion = floatval($moodleversion);
        }        
        
        if ($moodleversion >= 2.6) {
            $types = \core_component::get_plugin_types();
        } else if ($moodleversion >= 2.0) {
            $types = get_plugin_types();
        } else {
            // Moodle 1.9 does not give us a way to determine plugin 
            // installation paths.
            $types = array();
        }
        
        if (empty($types) || !array_key_exists($type, $types)) {
            // Either the moodle version is lower than 2.0, in which case we
            // don't have a reliable way of determining the install path, or the
            // plugin is of an unknown type.
            // 
            // Let's fall back to make our best guess.
            return $CFG->dirroot . '/' . $type; 
        }
        
        return $types[$type];
    }

    function get_plugin_url($pluginlist, $pluginfullname, $moodleversion, $pluginversion) {
        foreach($pluginlist->plugins as $k=>$plugin) {
            if(!$plugin->component) {
                continue;
            }
            if($plugin->component == $pluginfullname) {
                foreach($plugin->versions as $j) {
                    foreach($j->supportedmoodles as $v) {
                        if($v->release == $moodleversion && $v->version == $pluginversion) {
                            $downloadurl = $j->downloadurl;

                            return $downloadurl;
                        }
                    }
                }
            }
        }
    }
    
    function get_plugin_giturl($pluginlist, $pluginfullname, $moodleversion, $pluginversion) {
        foreach($pluginlist->plugins as $k=>$plugin) {
            if(!$plugin->component) {
                continue;
            }
            if($plugin->component == $pluginfullname) {
                return $plugin->source;
            }
        }
    }
}

