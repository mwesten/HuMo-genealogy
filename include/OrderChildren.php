<?php

/**
 * Class to order children by birth or baptismdate.
 * 
 * Oct. 2025 Huub: added this new class, improved processing of dates.
 */

//declare(strict_types=1);

namespace Genealogy\Include;

class OrderChildren
{
    public function order($dbh, $db_functions, $fam_id): bool
    {
        $children = $db_functions->get_children($fam_id);
        $order_changed = false;
        if (count($children) > 1) {
            foreach ($children as $child) {
                $childDb = $db_functions->get_person_with_id($child->person_id);
                $relation_id = $child->id;
                if ($childDb->pers_birth_date) {
                    $children_array[$relation_id] = $this->process_date($childDb->pers_birth_day, $childDb->pers_birth_month, $childDb->pers_birth_year);
                } elseif ($childDb->pers_bapt_date) {
                    $children_array[$relation_id] = $this->process_date($childDb->pers_bapt_day, $childDb->pers_bapt_month, $childDb->pers_bapt_year);
                } else {
                    $children_array[$relation_id] = '';
                }
            }

            // Store the original order
            $original_order = array_keys($children_array);

            // Sort children by date
            asort($children_array);

            // Store the new order
            $new_order = array_keys($children_array);

            // Check if the order has changed
            $order_changed = ($original_order !== $new_order);

            // Update the database if the order has changed
            if ($order_changed) {
                $order = 1;
                foreach ($children_array as $id => $val) {
                    $update_rel = $dbh->prepare("UPDATE humo_relations_persons SET relation_order = :relation_order WHERE id = :id");
                    $update_rel->execute([
                        ':relation_order' => $order,
                        ':id' => $id
                    ]);
                    $order++;
                }
            }
            unset($children_array);
        }
        return $order_changed;
    }

    private function process_date($day, $month, $year): string
    {
        $day = str_pad((int)$day, 2, '0', STR_PAD_LEFT);
        $month = str_pad((int)$month, 2, '0', STR_PAD_LEFT);
        $year = str_pad((int)$year, 4, '0', STR_PAD_LEFT);
        return "{$year}-{$month}-{$day}"; // Format YYYY-MM-DD
    }
}
