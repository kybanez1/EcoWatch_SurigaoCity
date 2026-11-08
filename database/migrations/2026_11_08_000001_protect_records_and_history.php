<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe forward migration. It never deletes a row.
 *
 * 1. Deleting a truck or a barangay can no longer silently delete (or blank out) routes, schedules,
 *    notes or report history. The database now says "restrict" instead of "cascade".
 * 2. Routes and schedules that already point to a deleted truck/barangay (left behind by the old
 *    delete) are kept. The missing link is set to NULL, so the CGSO sees "needs reassignment"
 *    and chooses a replacement truck/barangay.
 * 3. Collection notes remember the truck, route and barangay names from the day they were written.
 * 4. report_logs.message becomes TEXT so every accepted reason fits in MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 4. Long history messages (reason up to 255 characters + the text before it) ----
        Schema::table('report_logs', function (Blueprint $table) {
            $table->text('message')->change();
        });

        // ---- 3. Notes keep the details from when they were written ----
        Schema::table('collection_notes', function (Blueprint $table) {
            $table->string('truck_label', 40)->nullable()->after('truck_route_id');
            $table->string('route_name', 40)->nullable()->after('truck_label');
            $table->string('barangay_name', 80)->nullable()->after('route_name');
        });

        // ---- 1 + 2. Keys that point to trucks and barangays ----
        // [table, column, parent table, make the column nullable?]
        $links = [
            ['truck_routes', 'truck_id', 'trucks', true],
            ['truck_routes', 'barangay_id', 'barangays', true],
            ['schedules', 'truck_id', 'trucks', true],
            ['schedules', 'barangay_id', 'barangays', true],
            ['collection_notes', 'truck_id', 'trucks', true],
            ['reports', 'assigned_truck_id', 'trucks', false],
            ['report_assignments', 'truck_id', 'trucks', false],
            ['reports', 'barangay_id', 'barangays', false],
        ];
        foreach ($links as $link) {
            $this->protectLink($link[0], $link[1], $link[2], $link[3]);
        }

        // Links that already allow NULL: just clean up values that point to deleted rows.
        $this->clearDanglingValues('schedules', 'truck_route_id', 'truck_routes');
        $this->clearDanglingValues('collection_notes', 'truck_route_id', 'truck_routes');

        // ---- 3 (continued). Fill the note snapshot for the notes that already exist ----
        $this->fillNoteSnapshots();
    }

    public function down(): void
    {
        // Put the old delete rules back. Columns that became nullable stay nullable (that is harmless).
        $this->setDeleteRule('truck_routes', 'truck_id', 'trucks', 'cascade');
        $this->setDeleteRule('truck_routes', 'barangay_id', 'barangays', 'cascade');
        $this->setDeleteRule('schedules', 'truck_id', 'trucks', 'cascade');
        $this->setDeleteRule('schedules', 'barangay_id', 'barangays', 'cascade');
        $this->setDeleteRule('collection_notes', 'truck_id', 'trucks', 'cascade');
        $this->setDeleteRule('reports', 'assigned_truck_id', 'trucks', 'set null');
        $this->setDeleteRule('report_assignments', 'truck_id', 'trucks', 'set null');
        $this->setDeleteRule('reports', 'barangay_id', 'barangays', 'cascade');

        Schema::table('collection_notes', function (Blueprint $table) {
            $table->dropColumn(['truck_label', 'route_name', 'barangay_name']);
        });
        // report_logs.message stays TEXT: shrinking it again could cut long messages.
    }

    /** Names of the foreign keys that exist on one column (empty when the table has none). */
    private function foreignKeyNames($table, $column)
    {
        $names = [];
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                $names[] = $foreignKey['name'];
            }
        }
        return $names;
    }

    /** Sets values that point to a row that no longer exists to NULL. Nothing is deleted. */
    private function clearDanglingValues($table, $column, $parentTable)
    {
        DB::table($table)
            ->whereNotNull($column)
            ->whereNotIn($column, DB::table($parentTable)->select('id'))
            ->update([$column => null]);
    }

    /** SQLite (used only by the quick automated tests) cannot change foreign keys by name. MySQL can. */
    private function canChangeForeignKeys()
    {
        return Schema::getConnection()->getDriverName() !== 'sqlite';
    }

    private function protectLink($table, $column, $parentTable, $makeNullable)
    {
        if (! $this->canChangeForeignKeys()) {
            if ($makeNullable) {
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->unsignedBigInteger($column)->nullable()->change();
                });
            }
            $this->clearDanglingValues($table, $column, $parentTable);
            return;
        }

        foreach ($this->foreignKeyNames($table, $column) as $name) {
            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropForeign($name);
            });
        }

        if ($makeNullable) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->unsignedBigInteger($column)->nullable()->change();
            });
            $this->clearDanglingValues($table, $column, $parentTable);
        } else {
            // A column that must stay filled: only add the key when no row points to a deleted parent.
            $hasDangling = DB::table($table)
                ->whereNotNull($column)
                ->whereNotIn($column, DB::table($parentTable)->select('id'))
                ->exists();
            if ($hasDangling) {
                // reports.assigned_truck_id and report_assignments.truck_id allow NULL, the others do not.
                if ($this->columnAllowsNull($table, $column)) {
                    $this->clearDanglingValues($table, $column, $parentTable);
                } else {
                    return; // leave this one alone; the application code handles the missing parent safely
                }
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $parentTable) {
            $blueprint->foreign($column)->references('id')->on($parentTable)->restrictOnDelete();
        });
    }

    private function columnAllowsNull($table, $column)
    {
        foreach (Schema::getColumns($table) as $info) {
            if ($info['name'] === $column) {
                return (bool) $info['nullable'];
            }
        }
        return false;
    }

    private function setDeleteRule($table, $column, $parentTable, $rule)
    {
        if (! $this->canChangeForeignKeys()) {
            return;
        }
        foreach ($this->foreignKeyNames($table, $column) as $name) {
            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropForeign($name);
            });
        }
        Schema::table($table, function (Blueprint $blueprint) use ($column, $parentTable, $rule) {
            $foreign = $blueprint->foreign($column)->references('id')->on($parentTable);
            if ($rule === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->nullOnDelete();
            }
        });
    }

    private function fillNoteSnapshots()
    {
        DB::table('collection_notes')->orderBy('id')->chunkById(200, function ($notes) {
            foreach ($notes as $note) {
                $update = [];

                if ($note->truck_id) {
                    $truck = DB::table('trucks')->where('id', $note->truck_id)->first();
                    if ($truck) {
                        // Same label as Truck::label: TRUCK-001 becomes "Truck #01".
                        $number = (int) preg_replace('/\D/', '', $truck->truck_code);
                        $update['truck_label'] = 'Truck #' . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                    }
                }
                if ($note->truck_route_id) {
                    $route = DB::table('truck_routes')->where('id', $note->truck_route_id)->first();
                    if ($route) {
                        $update['route_name'] = $route->name;
                        if ($route->barangay_id) {
                            $update['barangay_name'] = DB::table('barangays')->where('id', $route->barangay_id)->value('name');
                        }
                    }
                }

                if ($update) {
                    DB::table('collection_notes')->where('id', $note->id)->update($update);
                }
            }
        });
    }
};
