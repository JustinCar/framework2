<?php
/**
 * A model class for the RedBean object Upload
 *
 * @author Lindsay Marshall <lindsay.marshall@ncl.ac.uk>
 * @copyright 2015-2016 Newcastle University
 *
 */
    namespace Model;
/**
 * Upload table stores info about files that have been uploaded...
 */
    class Upload extends \RedBeanPHP\SimpleModel
    {
        use \ModelExtend\Upload;
/**
 * Return the owner of this uplaod
 *
 * @return object
 */
	public function owner()
	{
	    return $this->bean->user;
	}
/**
 * Store a file
 *
 * @param object	$context	The context object for the site
 * @param array         $da     	The relevant $_FILES element (or similar generated by FormData)
 * @param boolean	$public		If TRUE then store in the public directory
 * @param object	$owner		The user who owns the upload. If NULL then  the currently logged in user
 */
	public function savefile($context, array $da, bool $public, $owner = NULL)
	{
	    if ($da['size'] == 0 || $da['error'] != UPLOAD_ERR_OK)
	    { # 0 length file or there was an error so ignore
                return FALSE;
	    }
	    if (!$public && !is_object($owner))
	    {
                if (!$context->hasuser())
                { # no logged in user! This should never happen...
                    @chdir($dir);
                    throw new Exception('No user');
                }
                $owner = $context->user();
	    }
            $dir = getcwd();
	    chdir($context->local()->basedir());
	    $pname = [$public ? 'public' : 'private', is_object($owner) ? $owner->getID() : 0, date('Y'), date('m')];
            foreach ($pname as $pd)
            { # walk the path cding and making if needed
                $this->mkch($pd);
            }
	    $fname = uniqid('', TRUE).'.'.pathinfo($da['name'], PATHINFO_EXTENSION);
	    if (!@move_uploaded_file($da['tmp_name'], $fname))
            {
                @chdir($dir);
                throw new Exception('Cannot move uploaded file to '.$fname);
            }
	    $this->bean->added = \R::isodatetime();
	    $pname[] = $fname;
	    $this->bean->fname = DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $pname);
            $this->bean->filename = $da['name'];
	    $this->bean->public = $public ? 1: 0;
	    $this->bean->user = $owner;
	    \R::store($this->bean);
	    if (!@chdir($dir))
            { # go back to where we were in the file system
                throw new Exception('Cannot chdir ', $dir);
            }
	    return TRUE;
	}
 /**
 * Make a directory if necessary and cd into it
 *
 * @param string    $dir The directory name
 *
 * @throws Cannot mkdir
 * @throws Cannot chdir
 *
 * @return void
 */
        private static function mkch(string $dir)
        {
            if (!file_exists($dir))
            {
                if (!@mkdir($dir, 0770))
                {
                    throw new Exception('Cannot mkdir ', $dir);
                }
            }
            if (!@chdir($dir))
            {
                throw new Exception('Cannot chdir ', $dir);
            }
        }
    }
?>
