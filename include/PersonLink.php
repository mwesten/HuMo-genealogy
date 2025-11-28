<?php

/*	*** Get person link ***
*	16-07-2021: Removed variable: pers_indexnr.
*	29-02-2020: URL construction in personLink class.
*	17-06-2025 Huub Mons: added separate class for person link handling.
*
*	Person url example (optional: "main_person=I23"): http://localhost/humo-genealogy/family/2/F10?main_person=I23/
*	$url=$personLink->PersonLink($personDb);
*/

namespace Genealogy\Include;

use Genealogy\Include\ProcessLinks;

class PersonLink
{
    private $processLinks;

    public function __construct()
    {
        $this->processLinks = new ProcessLinks();
    }

    public function get_person_link($personDb, $path = ''): string
    {
        // TODO check global.
        global $db_functions, $uri_path;

        if ($path) {
            $uri_path = $path;
        }

        $vars['pers_family'] = '';
        $firstRelation = $db_functions->get_first_relation($personDb->pers_id);
        if (isset($firstRelation->relation_gedcomnumber)) {
            $vars['pers_family'] = $firstRelation->relation_gedcomnumber;
        }
        elseif (isset($personDb->parent_relation_gedcomnumber)) {
            $vars['pers_family'] = $personDb->parent_relation_gedcomnumber;
        }
        $url = $this->processLinks->get_link($uri_path, 'family', $personDb->pers_tree_id, true, $vars);
        if ($personDb->pers_gedcomnumber) {
            $url .= "main_person=" . $personDb->pers_gedcomnumber;
        }
        return $url;
    }
}
