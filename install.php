<?php
/**
 * This contains the code to initialise the framework from the web
 *
 * @author Lindsay Marshall <lindsay.marshall@ncl.ac.uk>
 * @copyright 2014-2018 Newcastle University
 */
    global $cwd, $verbose;

    define('DBPREFIX', 'fw');
/**
 * Function to cleanup after errors
 *
 * Not everything needs to be cleaned up though, just things that will
 * stop the installer from running again.
 *
 * @return void
 */
    function cleanup()
    {
        global $cwd, $verbose;

        chdir($cwd);
        if ($verbose)
        {
            echo '<p>Cleaning '.$cwd.'</p>';
        }
        foreach (['class/config/config.php', '.htaccess'] as $file)
        {
            if (file_exists($file))
            {
                if ($verbose)
                {
                    echo '<p>Removing '.$file.'</p>';
                }
                @unlink($file);
            }
        }
    }
/**
 * Store a new framework config item
 *
 * @param string    $name
 * @param string    $value
 * @param bool          $local     If TRUE then this value should not be overwritten by remote updates
 *
 * @return void
 */
    function addfwconfig($name, $value, $local)
    {
        $fwc = \R::dispense(DBPREFIX.'config');
        $fwc->name = $name;
        $fwc->local = $local ? 1 : 0;
        if (is_array($value))
        {
            $fwc->value = $value[0];
            $fwc->fixed = $value[1];
            $fwc->integrity = $value[2];
            $fwc->crossorigin = $value[3];
            $fwc->defer = $value[4];
            $fwc->async = $value[5];
            $fwc->type = $value[6];
        }
        else
        {
            $fwc->value = $value;
            $fwc->fixed = 1;
            $fwc->integrity = '';
            $fwc->crossorigin = '';
            $fwc->defer = 0;
            $fwc->async = 0;
            $fwc->type = 'string';
        }
        \R::store($fwc);
    }

/**
 * Shutdown function - this is used to catch certain errors that are not otherwise trapped and
 * generate a clean screen as well as an error report to the developers.
 *
 * It also closes the RedBean connection
 *
 * @return void
 */
    function shutdown()
    {
        if ($error = error_get_last())
        { # are we terminating with an error?
            if (isset($error['type']) && ($error['type'] == E_ERROR || $error['type'] == E_PARSE || $error['type'] == E_COMPILE_ERROR))
            { # tell the developers about this
                echo '<h2>There has been an installer system error &ndash; '.$error['type'].'</h2>';
            }
            else
            {
                echo '<h2>There has been an installer system error</h2>';
            }
            echo '<pre>';
            var_dump($error);
            echo '</pre>';
            cleanup();
        }
        if (class_exists('R'))
        {
            \R::close(); # close RedBean connection
        }
    }
/**
 * Deal with untrapped exceptions - see PHP documentation
 *
 * @param Exception	$e
 */
    function exception_handler($e)
    {
        echo '<h2>There has been an installer system exception</h2>';
        echo '<pre>';
        var_dump($e);
        echo '</pre>';
        cleanup();
        exit;
    }
/**
 * Called when a PHP error is detected - see PHP documentation for details
 *
 * Note that we can chose to ignore errors. At the moment his is a fairly rough mechanism.
 * It could be made more subtle by allowing the user to specifiy specific errors to ignore.
 * However, exception handling is a much much better way of dealing with this kind of thing
 * whenever possible.
 *
 * @param int   	$errno
 * @param string	$errstr
 * @param string	$errfile
 * @param int    	$errline
 *
 * @return boolean
 */
    function error_handler(int $errno, string $errstr, string $errfile, int $errline) : bool
    {
        echo '<h2>There has been an installer system error : '.$errno.'</h2>';
        echo '<pre>';
        var_dump($errno, $errstr, $errfile, $errline);
        echo '</pre>';

        if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]))
        { # this is an internal error so we need to stop
            cleanup();
            exit;
        }
/*
 * If we get here it's a warning or a notice, so we aren't stopping
 *
 * Change this to an exit if you don't want to continue on any errors
 */
        return TRUE;
    }
    $verbose = isset($_GET['verbose']);
/*
 * Remember where we are in the file system
 */
    $cwd = __DIR__;
 /*
  * Set up all the system error handlers
  */
    error_reporting(E_ALL|E_STRICT);

    set_exception_handler('exception_handler');
    set_error_handler('error_handler');
    register_shutdown_function('shutdown');

    set_time_limit(120); # some people have very slow laptops and they run out of time on the installer.
/*
 * Initialise template engine - check to see if it is installed!!
 *
 */
    if (!file_exists('vendor'))
    {
        include 'install/errors/composer.php';
        exit;
    }
    include 'class/config/framework.php';
    \Config\Framework::initialise();
/**
 * Find out where we are
 *
 * Note that there issues with symbolic linking and __DIR__ being on a different path from the DOCUMENT_ROOT
 * DOCUMENT_ROOT seems to be unresolved
 *
 * DOCUMENT_ROOT should be a substring of __DIR__ in a non-linked situation.
 */
    $dn = preg_replace('#\\\\#', '/', __DIR__); # windows installers have \ in the name
    $sdir = preg_replace('#/+$#', '', $_SERVER['DOCUMENT_ROOT']); # remove any trailing / characters
    while (strpos($dn, $sdir) === FALSE)
    { # ugh - not on the same path
        $sdn = $sdir;
        $sdr = [];
        while (!is_link($sdn) && $sdn != '/')
        {
            $pp = pathinfo($sdn);
            $sdn = $pp['dirname'];
            $sdr[] = $pp['basename'];
        }
        if (is_link($sdn))
        { # not a symbolic link clearly.
            $sdir = preg_replace('#/+$#', '', readlink($sdn).'/'.implode('/', $sdr));
        }
        else
        {
            include 'install/errors/symlink.php';
            exit;
        }
    }
    $bdr = [];
    while ($dn != $sdir)
    { // go backwards till we get to document root
        $pp = pathinfo($dn);
        $dn = $pp['dirname'];
        $bdr[] = $pp['basename'];
    }
    if (empty($bdr))
    {
        $dir = '';
        $name = 'framework';
    }
    else
    {
        $dir = '/'.implode('/', $bdr);
        $name = array_pop($bdr);
    }
/*
 * URLs for various client side packages that are used by the installer and by the framework
 *
 * N.B. WHEN UPDATING THESE DON'T FORGET TO UPDATE THE CSP LOCATIONS IF NECESSARY!!!!!!!!!
 *
 * fwurls is used in some of the error gwnerating files os it ne3eds to set up here.
 */
    $fwurls = [ // url, fixed, integrity, crossorigin, defer, async, type
// CSS
        'bootcss'       => ['//stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css', 1, 'sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO', 'anonymous', 0, 0, 'css'],
//        'editablecss'   => ['//cdnjs.cloudflare.com/ajax/libs/x-editable/1.5.1/bootstrap3-editable/css/bootstrap-editable.css', 1, '', '', 0, 0, 'css'],
        'editablecss'   => [$dir.'/assets/css/bs4-editable.css', 1, 'sha384-lASoplRCh9raTlvIKvrhqO3avLn7BFQyvIOBEl2orbulYLy3fRL73IyCvt+FRy3K', 'anonymous', 0, 0, 'css'],
        'facss'         => ['https://use.fontawesome.com/releases/v5.3.1/css/all.css', 1, 'sha384-mzrmE5qonljUremFsqc01SB46JvROS7bZs3IO2EmfFsd15uHvIt+Y8vEf7N7fWAU', 'anonymous', 0, 0, 'css'],
// JS
        'jquery'        => ['https://code.jquery.com/jquery-3.3.1.min.js', 1, 'sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=', 'anonymous', 0, 0, 'js'],
        'jqueryslim'    => ['https://code.jquery.com/jquery-3.3.1.slim.min.js', 1, 'sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo', 'anonymous', 0, 0, 'js'],
        'bgfadejs'      => [$dir.'/assets/js/bgfade-min.js', 1, 'sha384-OZ82ALgTHP9CzBGSwr4Bk9tGOey9X7hAgfjZp8+QctHo93rb0o/Q6/g44MxeyxFZ', 'anonymous', 0, 0, 'js'],
        'bootjs'        => ['//stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js', 1, 'sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy', 'anonymous', 0, 0, 'js'],
        'bootbox'       => ['//cdnjs.cloudflare.com/ajax/libs/bootbox.js/4.4.0/bootbox.min.js', 1, '', '', 0, 0, 'js'],
//        'editable'      => ['//cdnjs.cloudflare.com/ajax/libs/x-editable/1.5.1/bootstrap3-editable/js/bootstrap-editable.min.js', 1, '', '', 0, 0, 'js'],
        'editable'      => [$dir.'/assets/js/bs4-editable-min.js', 1, 'sha384-/lvU+ClL4s1GKVBbT2Ba3GAw2MVWch2EFalr3wsmDC2/rwuWYGcUMSANYEChU4eS', 'anonymous', 0, 0, 'js'],
        'parsley'       => ['//cdnjs.cloudflare.com/ajax/libs/parsley.js/2.8.1/parsley.min.js', 1, '', '', 0, 0, 'js'],
        'popperjs'      => ['//cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js', 1, '', '', 0, 0, 'js'],
        'utiljs'        => [$dir.'/assets/js/util-min.js', 1, 'sha384-9VKBwSH8YZVLtOgG0nfM/RQ2MryjGteF7CCN84RPbtbFuW0aRWHhbC8aKGGcr5Z/', 'anonymous', 0, 0, 'js'],
    ];
/**
 *  RedBean needs an alias to use namespaces
 */
    if (!class_alias('\RedBeanPHP\R','\R'))
    {
        include 'install/errors/notwig.php';
        exit;
    }

    try
    {
        $twig = new \Twig_Environment(
            new \Twig_Loader_Filesystem('./install/twigs'),
            ['cache' => FALSE, 'debug' => TRUE]
        );
    }
    catch (Exception $e)
    {
        include 'install/errors/notwig.php';
        exit;
    }
/**
 * Test some PHP installation features...
 */
    $hasmb = function_exists('mb_strlen');
    $haspdo = in_array('mysql', \PDO::getAvailableDrivers());

    if (!$hasmb || !$haspdo)
    {
        include 'install/errors/phpbuild.php';
        exit;
    }

    $tpl = 'install.twig';
    $host = $_SERVER['HTTP_HOST'];
    switch ($host)
    { # makes for a proper looking fake email address....
    case 'localhost':
    case '127.0.0.1':
        $host = 'localhost.org';
        break;
    }

    $beans = [
        'config',
        'confirm',
        'page',
        'pagerole',
        'role',
        'rolecontext',
        'rolename',
        'user',
    ];

    $fwcsp = [
        'default-src'   => ["'self'"],
        'font-src'      => ["'self'", 'use.fontawesome.com'],
        'img-src'       => ["'self'", 'data:'],
        'script-src'    => ["'self'", 'stackpath.bootstrapcdn.com', 'cdnjs.cloudflare.com', 'code.jquery.com'],
        'style-src'     => ["'self'", 'use.fontawesome.com', 'stackpath.bootstrapcdn.com'],
    ];
/*
 * See if we have a sendmail setting in the php.ini file
 */
    $sendmail = ini_get('sendmail_path');

/*
 * Set up important values
 */
    $vals = [
             'name'         => $name,
             'dir'          => __DIR__,
             'base'         => $dir,
             'fwurls'       => $fwurls,
             'siteurl'      => 'http://'.$host.$dir.'/',
             'noreply'      => 'noreply@'.$host,
             'adminemail'   => $_SERVER['SERVER_ADMIN'],
             'sendmail'     => $sendmail !== '',
        ];

    $fail = FALSE;
    if (preg_match('/#/', $name))
    { // names with # in them will break the regexp in Local debase()
        $fail = $vals['hashname'] = TRUE;
    }
    elseif (version_compare(phpversion(), '7.1.0', '<')) {
        $fail = $vals['phpversion'] = TRUE;
    }
    elseif (!function_exists('password_hash'))
    {
        $fail = $vals['phpversion'] = TRUE;
    }

    if (!is_writable('.'))
    {
        $fail = $vals['nodotgw'] = TRUE;
    }

    if (!is_writable('class/config'))
    {
        $fail = $vals['noclassgw'] = TRUE;
    }

    if (file_exists('.htaccess') && !is_writable('.htaccess'))
    {
        $fail = $vals['nowhtaccess'] = TRUE;
    }

/*
 * We need to know some option selections to do some requirements checking
 */
    $flags = [
        'private', 'public', 'regexp', 'register', 'reportcsp', 'usecsp', 'usephpm',
    ];
    $cvalue = [];
    $options = [];
    foreach ($flags as $fn)
    {
        $options[$fn] = filter_has_var(INPUT_POST, $fn);
        $cvalue[$fn] = $options[$fn] ? 1 : 0;
    }

    if ($options['public'])
    {
        if (!is_writable('assets'))
        {
            $fail = $vals['noassets'] = TRUE;
        }
    }

    $vals['fail'] = $fail;
    $hasconfig = file_exists('class/config.php');
    $hashtaccess  = file_exists('.htaccess');
//    $vals['hasconfig'] = $hasconfig;
//    $vals['hashtaccess'] =  $hashtaccess;
    if (!$fail && filter_has_var(INPUT_POST, 'sitename'))
    { # this is an installation attempt
        $cvars = [
            'dbhost'        => ['DBHOST', FALSE, TRUE, 'string'], # name of const, add to DB, non-optional, type
            'dbname'        => ['DB', FALSE, TRUE, 'string'],
            'dbuser'        => ['DBUSER', FALSE, TRUE, 'string'],
            'dbpass'        => ['DBPW', FALSE, TRUE, 'string'],
            'sitename'      => ['SITENAME', TRUE, TRUE, 'string'],
            'siteurl'       => ['SITEURL', TRUE, TRUE, 'string'],
            'sitenoreply'   => ['SITENOREPLY', TRUE, TRUE, 'string'],
            'sysadmin'      => ['SYSADMIN', TRUE, TRUE, 'string'],
            'admin'         => ['', FALSE, TRUE],
            'adminpw'       => ['', FALSE, TRUE],
            'cadminpw'      => ['', FALSE, TRUE],
            'regexp'        => ['DBRX', FALSE, FALSE, 'bool'],
            'register'      => ['REGISTER', FALSE, FALSE, 'bool'],
            'public'        => ['UPUBLIC', FALSE, FALSE, 'bool'],
            'private'       => ['UPRIVATE', FALSE, FALSE, 'bool'],
            'usecsp'        => ['USECSP', TRUE, FALSE, 'bool'],
            'reportcsp'     => ['REPORTCSP', TRUE, FALSE, 'bool'],
            'usephpm'       => ['USEPHPM', FALSE, FALSE, 'bool'],
            'smtphost'      => ['SMTPHOST', FALSE, FALSE, 'string'],
            'smtpport'      => ['SMTPPORT', FALSE, FALSE, 'string'],
            'protocol'      => ['PROTOCOL', FALSE, FALSE, 'string'],
            'smtpuser'      => ['SMTPUSER', FALSE, FALSE, 'string'],
            'smtppass'      => ['SMTPPW', FALSE, FALSE, 'string'],
            'csmtppass'     => ['', FALSE, FALSE, 'string'],
        ];

        foreach (array_keys($cvars) as $v)
        {
            if (filter_has_var(INPUT_POST, $v))
            {
                $cvalue[$v] = trim($_POST[$v]);
            }
            elseif ($cvars[$v][2])
            { // that variable must be present
                header('HTTP/1.1 400 Bad Request');
                exit;
            }
        }
        $direrr = [];
        if (!file_exists('debug'))
        {
            if (!@mkdir('debug', 0770)) // make a directory for debugging output
            {
                $direrr[] = 'Cannot create directory "debug"';
            }
        }
/*
 *  Make directories for uploads if required
 */
        if ($options['public'] && !file_exists('assets'.DIRECTORY_SEPARATOR.'public'))
        { # make the directory for public files
            if (!@mkdir('assets'.DIRECTORY_SEPARATOR.'public', 0766))
            {
                $direrr[] = 'Cannot create directory "assets'.DIRECTORY_SEPARATOR.'public"';
            }
        }

        if ($options['private'] && !file_exists('private'))
        { # make the directory for private files
            if (!@mkdir('private', 0766))
            {
                $direrr[] = 'Cannot create directory "private"';
            }
        }

        if (!file_exists('twigcache'))
        {
            if (!@mkdir('twigcache')) # in case we turn caching on for twig.
            {
                $direrr[] = 'Cannot create directory "twigcache"';
            }
        }

        if (!empty($direrr))
        {
            $vals['direrr'] = TRUE;
            $vals['dirmsg'] = $direrr;
            $vals['fail'] = TRUE;
        }
        else
        {
/*
 * Setup the config.php file in the lib directory
 */
            $fd = fopen('class/config/config.php', 'w');
            if ($fd === FALSE)
            {
                header('HTTP/1.1 500 Internal Error');
                exit;
            }
            fputs($fd, '<?php'.PHP_EOL.'    namespace Config;'.PHP_EOL);
            fputs($fd, '/**'.PHP_EOL.' * Generated by framework installer - '.date('r').PHP_EOL.'*/'.PHP_EOL.'    class Config'.PHP_EOL.'    {'.PHP_EOL);
            fputs($fd, "        const BASEDNAME\t= '".$dir."';".PHP_EOL);
            fputs($fd, "        const SESSIONNAME\t= '".'PSI'.preg_replace('/[^a-z0-9]/i', '', $cvalue['sitename'])."';".PHP_EOL);
            foreach ($cvars as $fld => $pars)
            {
                if ($pars[0] !== '')
                { # Only save relevant values - see above
                    switch($pars[3])
                    {
                    case 'string':
                        if (isset($cvalue[$fld]))
                        {
                            fputs($fd, "        const ".$pars[0]."\t= ");
                            fputs($fd, "'".$cvalue[$fld]."';".PHP_EOL);
                        }
                        elseif ($pars[2])
                        { // this is required
                        }
                        break;
                    case 'bool':
                        if (isset($options[$fld]))
                        {
                            fputs($fd, "        const ".$pars[0]."\t= ");
                            fputs($fd, ($options[$fld] ? 'TRUE' : 'FALSE').';'.PHP_EOL);
                        }
                        elseif ($pars[2])
                        { // this is required
                        }
                        break;
                    }
                }
            }
            //fputs($fd, "\tconst DBOP\t= '".($options['regexp'] ? ' regexp ' : '=')."';".PHP_EOL);
            foreach ($beans as $b)
            {
                fputs($fd, "        const ".strtoupper($b)."\t= '".DBPREFIX.$b."';".PHP_EOL);
            }

            fputs($fd, "
        public static function setup()
        {
            \\Framework\\Web\\Web::getinstance()->addheader([
            'Date'              => gmstrftime('%b %d %Y %H:%M:%S', time()),
            'Window-target'     => '_top',      # deframes things
            'X-Frame-Options'	=> 'DENY',      # deframes things
            'Content-Language'	=> 'en',
            'Vary'              => 'Accept-Encoding',
            ]);
        }".PHP_EOL.PHP_EOL);

            fputs($fd, '
        public static $defaultCSP = ['.PHP_EOL);
            foreach ($fwcsp as $key => $val)
            {
                fputs($fd, "                '".$key."' => [\"".implode('", "', $val).'"],'.PHP_EOL);
            }
            fputs($fd, '        ];'.PHP_EOL);
            fputs($fd, '    }'.PHP_EOL.'?>');
            fclose($fd);
    /*
     * Setup the .htaccess file
     */
            $fd = fopen('.htaccess', 'w');
            if ($fd === FALSE)
            {
                cleanup();
                header('HTTP/1.1 500 Internal Error');
                exit;
            }
            fputs($fd, 'RewriteEngine on'.PHP_EOL.'Options -Indexes +FollowSymlinks'.PHP_EOL);
            fputs($fd, 'RewriteBase '.($dir === '' ? '/' : $dir).PHP_EOL);
            fputs($fd,
                'RewriteRule ^ajax.* ajax.php [L,NC,QSA]'.PHP_EOL.
                'RewriteRule ^(assets'.($options['public'] ? '|public' : '').')/(.*) $1/$2 [L,NC]'.PHP_EOL.
    //            'RewriteRule ^(themes/[^/]*/assets/(css|js)/[^/]*) $1 [L,NC]'.PHP_EOL.
                'RewriteRule ^.*$ index.php [L,QSA]'.PHP_EOL.PHP_EOL.
                '# uncomment these to turn on compression of responses'.PHP_EOL.
                '# Apache needs the deflate module and PHP needs the zlib module for these to work'.PHP_EOL.
                '# AddOutputFilterByType DEFLATE text/css'.PHP_EOL.
                '# AddOutputFilterByType DEFLATE text/javascript'.PHP_EOL.
                '# php_flag zlib.output_compression  On'.PHP_EOL.
                '# php_value zlib.output_compression_level 5'.PHP_EOL

            );
            fclose($fd);
    /*
     * Try opening the database and setting up the User table
     */
            try
            {
                $now = \R::isodatetime(time() - date('Z')); # make sure the timestamp is in UTC (this should fix a weird problem with some XAMPP installations)
                $vals['dbhost'] = $cvalue['dbhost'];
                $vals['dbname'] = $cvalue['dbname'];
                $vals['dbuser'] = $cvalue['dbuser'];
                \R::setup('mysql:host='.$cvalue['dbhost'].';dbname='.$cvalue['dbname'], $cvalue['dbuser'], $cvalue['dbpass']); # mysql initialiser
                \R::freeze(FALSE); // we need to be able to update things on the fly!
                \R::nuke(); // clear everything.....
                $user = R::dispense('user');
                $user->email = $cvalue['sysadmin'];
                $user->login = $cvalue['admin'];
                $user->password = password_hash($cvalue['adminpw'], PASSWORD_DEFAULT);
                $user->active = 1;
                $user->confirm = 1;
                $user->joined = $now;
                \R::store($user);
    /**
     * Now initialise the confirmation code table
     */
                $conf = R::dispense('confirm');
                $conf->code = 'this is a rubbish code';
                $conf->issued = $now;
                $conf->kind = 'C';
                \R::store($conf);
                $user->xownConfirm[] = $conf;
                \R::store($user);
                \R::trash($conf);
    ///**
    // * Check that timezone setting for PHP has not made the date into the future...
    // */
    //            $dt = \R::findOne('user', 'joined > NOW()');
    //            if (is_object($dt))
    //            {
    //                $vals['timezone'] = TRUE;
    //            }
    /**
     * Save some framework configuration information into the database
     * This will make it easier to remote updating of the system once
     * it is up and running
     */
                foreach ($cvars as $fld => $pars)
                {
                    if ($pars[1])
                    {
                        addfwconfig($fld, $cvalue[$fld], TRUE);
                    }
                }
                foreach ($fwurls as $k => $v)
                {
                    addfwconfig($k, $v, FALSE);
                }
    /**
     * Set up some roles for access control:
     *
     * Admin for the Site
     * Developer for the Site
     *
     * These are both granted to the admin user.
     */
                $cname = \R::dispense('rolecontext');
                $cname->name = 'Site';
                $cname->fixed = 1;
                \R::store($cname);
    // Admin role name
                $arname = \R::dispense('rolename');
                $arname->name = 'Admin';
                $arname->fixed = 1;
                \R::store($arname);

                $role = \R::dispense('role');
                $role->otherinfo = '-';
                $role->start = $now;
                $role->end =   $now; # this makes RedBean make it a datetime field
                \R::store($role);
                $role->end = NULL; # clear end date as we don't want to time limit admin
                \R::store($role);
                $user->xownRole[] = $role;
                $cname->xownRole[] = $role;
                $arname->xownRole[] = $role;
                \R::store($arname);
    // Developer Role name
                $drname = \R::dispense('rolename');
                $drname->name = 'Developer';
                \R::store($drname);

                $role = \R::dispense('role');
                $role->otherinfo = '-';
                $role->start = $now;
                $role->end = NULL; # no end date
                \R::store($role);
                $user->xownRole[] = $role;
                $cname->xownRole[] = $role;
                $drname->xownRole[] = $role;
                \R::store($user);
                \R::store($cname);
                \R::store($drname);
    /**
     * See code below for significance of the entries (kind, source, admin, needlogin, devel, active)
     *
     * the link for install.php is to catch when people try to run install again after a successful install
     */
                $pages = [
                    'about'         => [\Framework\SiteAction::TEMPLATE, '@content/about.twig', FALSE, 0, FALSE, 1],
                    'admin'         => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\Admin', TRUE, 1, FALSE, 1],
                    'assets'        => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\Assets', TRUE, 1, FALSE, 0],          # not active - really only needed when total cacheability is needed
                    'confirm'       => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\UserLogin', FALSE, 0, FALSE, $options['register'] ? 1 : 0],
                    'contact'       => [\Framework\SiteAction::OBJECT, '\\Pages\\Contact', FALSE, 0, FALSE, 1],
                    'cspreport'     => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\CSPReport', FALSE, 0, FALSE, $options['reportcsp'] ? 1 : 0],
                    'devel'         => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\Developer', TRUE, 1, TRUE, 1],
                    'forgot'        => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\UserLogin', FALSE, 0, FALSE, 1],
                    'home'          => [\Framework\SiteAction::OBJECT, '\\Pages\\Home', FALSE, 0, FALSE, 1],
                    'install.php'   => [\Framework\SiteAction::TEMPLATE, '@util/oops.twig', FALSE, 0, FALSE, 1],
                    'login'         => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\UserLogin', FALSE, 0, FALSE, 1],
                    'logout'        => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\UserLogin', FALSE, 1, FALSE, 1],
                    'private'       => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\GetFile', FALSE, 1, FALSE, $options['private'] ? 1 : 0],
                    'register'      => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\UserLogin', FALSE, 0, FALSE, $options['register'] ? 1 : 0],
                    'upload'        => [\Framework\SiteAction::OBJECT, '\\Framework\\Pages\\Upload', FALSE, 0, FALSE, $options['public'] || $options['private'] ? 1 : 0],
                ];
                foreach ($pages as $pname => $data)
                {
                    $page = \R::dispense('page');
                    $page->name = $options['regexp'] ? '^'.$pname.'$' : $pname;
                    $page->kind = $data[0];
                    $page->source = $data[1];
                    $page->needlogin = $data[3];
                    $page->mobileonly = 0;
                    $page->active = $data[5];
                    \R::store($page);
                    if ($data[2])
                    { // must be an admin
                        $pagerole = \R::dispense('pagerole');
                        $pagerole->start = $now;
                        $pagerole->end = NULL;
                        $pagerole->otherinfo = '';
                        \R::store($pagerole);
                        $page->xownPageRole[] = $pagerole;
                        $cname->xownPageRole[] = $pagerole;
                        $arname->xownPageRole[] = $pagerole;
                        \R::store($page);
                        \R::store($cname);
                        \R::store($arname);
                    }
                    if ($data[4])
                    { // must be a developer
                        $pagerole = \R::dispense('pagerole');
                        $pagerole->start = $now;
                        $pagerole->end = NULL;
                        $pagerole->otherinfo = '';
                        \R::store($pagerole);
                        $page->xownPageRole[] = $pagerole;
                        $cname->xownPageRole[] = $pagerole;
                        $drname->xownPageRole[] = $pagerole;
                        \R::store($page);
                        \R::store($cname);
                        \R::store($drname);
                    }

                }
                $tpl = 'success.twig';
            }
            catch (Exception $e)
            { # something went wrong - so cleanup and try again...
                $vals['dberror'] = $e->getMessage();
                $vals['fail'] = TRUE;
                cleanup();
            }
        }
    }
    echo $twig->render($tpl, $vals);
?>
