<?php

/**
 * First version made by Yossi Beck.
 * $generation_number = generation number to process.
 * $nr_generations = maximum number of generations.
 *
 *  Descendant array:
 *  person                  descendant[1]
 *  child1                  descendant[2]
 *  child2                  descendant[3]
 *
 *  children of child1:
 *  child1                  descendant[4]
 *  child2                  descendant[5]
 *
 * April 2015 Huub Mons: created a general ancestors - descendants functions script.
 * Jan. 2024: at this moment this script is only used in editorModel.php to add a colour for descendants.
 * Sept. 2024: also used in maps script.
 * 
 * TODO: use this function for multiple scripts.
 */

//declare(strict_types=1);

namespace Genealogy\Include;

class Descendants
{
    private $generation_number = 0;
    private $descendant_id = 0;
    private $descendant_array = [];

    public function get_descendant_id()
    {
        return $this->descendant_id;
    }

    // TODO: at this moment $family_id = family gedcomnumber.
    public function get_descendants($family_id, $main_person, $nr_generations)
    {
        global $db_functions;

        // *** Selected person ***
        $this->descendant_id++;
        $this->descendant_array[$this->descendant_id] = $main_person;
        $this->generation_number++;
        if ($nr_generations < $this->generation_number) {
            return;
        }

        // *** Count marriages of main person (man) ***
        $family = $db_functions->get_family($family_id);
        $fam_id = '';
        //if ($family && isset($family->fam_gedcomnumber)) {
        if ($family && isset($family->fam_id)) {
            $fam_id = $family->fam_id;
        }

        //$familyDb = $db_functions->get_family_partners($family_id);
        $familyDb = $db_functions->get_family_partners($fam_id);
        $parent1_id = '';
        // *** Standard main_person is the father ***
        if ($familyDb->partner1_id) {
            $parent1_id = $familyDb->partner1_id;
        }
        // *** If mother is selected, mother will be main_person ***
        if ($familyDb->partner2_id == $main_person) {
            $parent1_id = $familyDb->partner2_id;
        }

        // *** Check family with parent1: N.N. ***
        if ($parent1_id) {
            $relations = $db_functions->get_relations($parent1_id);
        }

        // *** Loop multiple marriages of main_person ***
        foreach ($relations as $relation) {
            $familyDb = $db_functions->get_family($relation->relation_id);
            // *** Progen: onecht kind, vrouw zonder man ***
            //if ($familyDb->fam_kind!='PRO-GEN'){
            //  $family_nr++;
            //}

            if (!$familyDb || !isset($familyDb->fam_id)) {
                continue;
            }

            /**
             * Children
             */
            $children = $db_functions->get_children($familyDb->fam_id);
            if ($children) {
                foreach ($children as $child) {
                    // *** Get 1st family of child ***
                    $relations = $db_functions->get_first_relation($child->person_id);
                    if (isset($relations->relation_id) && $relations->relation_id) {
                        // *** Recursive, process ancestors of child ***
                        $this->get_descendants($relations->relation_gedcomnumber, $child->person_gedcomnumber, $nr_generations);
                    } else {
                        // *** Child without own family ***
                        $this->descendant_id++;
                        $this->descendant_array[$this->descendant_id] = $child->person_gedcomnumber;
                    }
                }
            }
        }
        return $this->descendant_array;
    }
}
