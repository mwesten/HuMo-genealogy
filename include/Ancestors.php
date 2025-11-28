<?php

/**
 * Original function used for ancestor sheet, made by Yossi.
 * April 2015 Huub Mons: added this function in general ancestor function script.
 * Jan. 2024 Huub Mons: improved function.
 *
 * ancestor[4] = father4   ancestor[5] = mother5   ancestor[6] = father6   ancestor[7] = mother7
 *                         ancestor[2] = father2   ancestor[3] = mother3
 *                                      ancestor[1] = person
 *
 * TODO: use this function for multiple scripts.
 */

namespace Genealogy\Include;

class Ancestors
{
    public function get_ancestors($db_functions, $main_person): array
    {
        $ancestor_array = array();

        // *** person 1 ***
        // TODO replace with: get_parents_relation function.
        $personDb = $db_functions->get_person($main_person, 'parentrelation');
        // *** Get parents ***
        if ($personDb->parent_relation_id) {
            $parentDb = $db_functions->get_family_partners($personDb->parent_relation_id);
            $ancestor_array[2] = $parentDb->partner1_gedcomnumber;
            $ancestor_array[3] = $parentDb->partner2_gedcomnumber;
        }

        // Loop to find person data
        $count_max = 4; // *** Start with value 4, can be raised in loop ***

        for ($counter = 2; $counter < $count_max; $counter++) {
            if (isset($ancestor_array[$counter])) {
                // TODO replace with: get_parents_relation function.
                $personDb = $db_functions->get_person($ancestor_array[$counter], 'parentrelation');
                // *** Get parents ***
                if (isset($personDb->parent_relation_id) && $personDb->parent_relation_id) {
                    $father_counter = $counter * 2;
                    $mother_counter = $father_counter + 1;
                    $parentDb = $db_functions->get_family_partners($personDb->parent_relation_id);

                    // *** Check if man is in array allready ***
                    if (!in_array($parentDb->partner1_gedcomnumber, $ancestor_array)) {
                        $ancestor_array[$father_counter] = $parentDb->partner1_gedcomnumber;
                        if ($father_counter > $count_max) {
                            $count_max = $father_counter;
                        }
                    }

                    // *** Check if woman is in array allready ***
                    if (!in_array($parentDb->partner2_gedcomnumber, $ancestor_array)) {
                        $ancestor_array[$mother_counter] = $parentDb->partner2_gedcomnumber;
                        if ($mother_counter > $count_max) {
                            $count_max = $mother_counter;
                        }
                    }
                }
            }
        }
        return $ancestor_array;
    }
}
