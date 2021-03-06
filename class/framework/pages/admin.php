<?php
/**
 * Contains definition of Admin class
 *
 * @author Lindsay Marshall <lindsay.marshall@ncl.ac.uk>
 * @copyright 2012-2015 Newcastle University
 */
    namespace Framework\Pages;

    use \Framework\Web\Web as Web;
    use \Support\Context as Context;

/**
 * A class that contains code to handle any /admin related requests.
 *
 * Admin status is checked in index.php so does not need to be done here.
 */
    class Admin extends \Framework\Siteaction
    {
        const EDITABLE = ['form', 'fwconfig', 'page', 'user'];
        const VIEWABLE = ['form'];
/**
 * Handle various admin operations /admin/xxxx
 *
 * @param object	$context	The context object for the site
 *
 * @return string	A template name
 */
        public function handle(Context $context)
        {
            $rest = $context->rest();
            switch ($rest[0])
            {
            case 'pages':
                $tpl = '@admin/pages.twig';
                break;

            case 'contexts':
                $tpl = '@admin/contexts.twig';
                break;

            case 'roles':
                $tpl = '@admin/roles.twig';
                break;

            case 'users':
                $tpl = '@admin/users.twig';
                break;

            case 'forms':
                $tpl = '@admin/forms.twig';
                break;

            case 'config':
                $tpl = '@admin/config.twig';
                break;

            case 'info':
                $_SERVER['PHP_AUTH_PW'] = '*************'; # hide the password in case it is showing.
                phpinfo();
                exit;

            case 'edit' : // Edit something - forms, user, pages...
                try
                {
                    if (count($rest) < 3)
                    {
                        throw new \Exception('Too Few');
                    }
                    $kind = $rest[1];
                    if (!in_array($kind, self::EDITABLE))
                    {
                        throw new \Exception('Not Editable');
                    }
                    $obj = $context->load($kind, $rest[2]);
                    $context->local()->addval('bean', $obj);
                    $obj->startEdit($context); // do any special setup that the edit requires
                    if (($bid = $context->formdata()->post('bean', '')) !== '')
                    { // this is a post
                        if ($bid != $obj->getID())
                        { # something odd...
                            throw new \Exception('Oddness');
                        }
                        \Framework\Utility\CSRFGuard::getinstance()->check();
                        list($error, $emess) = $obj->edit($context); // handle the edit result
                        if ($error)
                        {
                            $context->local()->message(\Framework\Local::ERROR, $emess);
                        }
                        // The edit call might divert to somewhere else so sometimes we may not get here.
                        $context->local()->message(\Framework\Local::MESSAGE, 'Saved');
                    }
                }
                catch (\Exception $e)
                {
                    $context->web()->bad();
                    /* NOT REACHED */
                }
                $tpl = '@edit/'.$kind.'.twig';
                break;

            case 'view' : // view something - forms only at the moment
                try
                {
                    if (count($rest) < 3)
                    {
                        throw new \Exception('Too few');
                    }
                    $kind = $rest[1];
                    if (!in_array($kind, self::VIEWABLE))
                    {
                        throw new \Exception('Not Viewable');
                    }
                }
                catch (\Exception $e)
                {
                    $context->local()->bad();
                    /* NOT REACHED */
                }
                $obj = $context->load($kind, $rest[2]);
                $obj->view(); // do any required set up
                $context->local()->addval('bean', $obj);
                $tpl = '@view/'.$kind.'.twig';
                break;

            case 'update':
                $updated = [];
                $upd = json_decode(file_get_contents('https://catless.ncl.ac.uk/framework/update/'));
                if (isset($upd->fwconfig))
                { // now see if there are any config values that need updating.
                    foreach ($upd->fwconfig as $cname => $cdata)
                    {
                        $lval = \R::findOne('fwconfig', 'name=?', [$cname]);
                        if (is_object($lval))
                        {
                            if ($lval->local == 0)
                            { // update if not locally set and there is a new value
                                $change = FALSE;
                                foreach ($cdata as $k => $v)
                                {
                                    if ($lval->$k != $v)
                                    {
                                        $lval->$k = $v;
                                        $change = TRUE;
                                    }
                                }
                                if ($change)
                                {
                                    \R::store($lval);
                                    $updated[$cname] = $cdata->value;
                                }
                            }
                        }
                        else
                        {
                            $lval = \R::dispense('fwconfig');
                            $lval->name = $cname;
                            $lval->local = 0;
                            foreach ($cdata as $k => $v)
                            {
                                $lval->$k = $v;
                            }
                            \R::store($lval);
                            $updated[$cname] = $cdata->value;
                        }
                    }
                    $context->local()->addval('version', $upd->version);
                    $context->local()->addval('updated', $updated);
                    $context->local()->addval('current', trim(file_get_contents($context->local()->makebasepath('version.txt'))));
                }
                else
                {
                    $context->local()->addval('nozip', TRUE);
                }
                $tpl = '@admin/update.twig';
                break;

            default :
                $tpl = '@admin/admin.twig';
                break;
            }
            return $tpl;
        }
    }
?>