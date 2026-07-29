<?php

namespace Webkul\Accounting\Services\Coa;

use Webkul\Accounting\Data\Coa\CoaRow;

/**
 * Turns flat CoA rows into a hierarchy plan: a de-duplicated set of group
 * (classification) nodes ordered parents-before-children, and one leaf account
 * per row pointing at its deepest group. Group nodes are keyed by their full
 * classification path, so a path shared by many rows produces exactly one node.
 */
class CoaHierarchyPlanner
{
    /**
     * @param  array<int, CoaRow>  $rows
     * @return array{groups: array<int, array<string, mixed>>, leaves: array<int, array<string, mixed>>}
     */
    public function plan(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $groups keyed by path label */
        $groups = [];
        $leaves = [];

        foreach ($rows as $row) {
            $path = $row->groupPath();

            // Register each ancestor path as a group node (idempotent by label).
            $ancestors = [];
            for ($depth = 1; $depth <= count($path); $depth++) {
                $slice = array_slice($path, 0, $depth);
                $label = implode(' > ', $slice);
                $parentLabel = $depth > 1 ? implode(' > ', array_slice($path, 0, $depth - 1)) : null;

                if (! isset($groups[$label])) {
                    $groups[$label] = [
                        'path'         => $slice,
                        'path_label'   => $label,
                        'name'         => $slice[$depth - 1],
                        'parent_label' => $parentLabel,
                        'depth'        => $depth,
                        'nature'       => $row->nature,
                        'class1'       => $row->classifications[0] ?? '',
                    ];
                }

                $ancestors[] = $label;
            }

            $leaves[] = [
                'row'                => $row,
                'code'               => $row->code,
                'title'              => $row->title,
                'parent_group_label' => $path !== [] ? end($ancestors) : null,
                'path_label'         => $row->classificationPathLabel(),
            ];
        }

        // Order groups parents-before-children so parent_id can be resolved.
        $orderedGroups = array_values($groups);
        usort($orderedGroups, fn ($a, $b) => $a['depth'] <=> $b['depth'] ?: strcmp($a['path_label'], $b['path_label']));

        return ['groups' => $orderedGroups, 'leaves' => $leaves];
    }
}
