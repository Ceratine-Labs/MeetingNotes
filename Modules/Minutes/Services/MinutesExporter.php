<?php

namespace Modules\Minutes\Services;

use Modules\Minutes\Models\Meeting;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\JcTable;

/**
 * Exports a ready minutes record to Markdown, PDF (mpdf, house
 * standard) or DOCX (PHPWord). All three read the canonical sections
 * struct / rendered_html — never the transcript, never the LLM.
 */
class MinutesExporter
{
    public function filename(Meeting $meeting, string $extension): string
    {
        $base = \Illuminate\Support\Str::slug($meeting->title ?? 'meeting-minutes') ?: 'meeting-minutes';
        $date = $meeting->meeting_date?->format('Y-m-d');

        return trim($base . ($date ? "-{$date}" : '')) . '.' . $extension;
    }

    // ------------------------------------------------------------------
    // Markdown
    // ------------------------------------------------------------------

    public function markdown(Meeting $meeting): string
    {
        $s = $meeting->sections;
        $info = $s['meeting_info'] ?? [];
        $att = $s['attendance'] ?? [];
        $ns = $s['next_steps'] ?? [];
        $na = fn ($v) => ($v !== null && $v !== '') ? $v : '[Not specified]';
        $out = [];

        $out[] = '# Meeting Minutes — ' . $na($info['title'] ?? null);
        $out[] = '';
        $out[] = '## 1. Meeting Information';
        $out[] = '';
        $out[] = '| | |';
        $out[] = '|---|---|';
        $out[] = '| **Title** | ' . $na($info['title'] ?? null) . ' |';
        $out[] = '| **Date** | ' . $na($info['date'] ?? null) . ' |';
        $time = trim(($info['start_time'] ?? '') . ((isset($info['end_time']) && $info['end_time'] !== '') ? ' – ' . $info['end_time'] : ''));
        $out[] = '| **Time** | ' . $na($time ?: null) . (! empty($info['duration']) ? " ({$info['duration']})" : '') . ' |';
        $out[] = '| **Location** | ' . $na($info['location'] ?? null) . ' |';
        $out[] = '| **Type** | ' . $na($info['meeting_type'] ?? null) . ' |';
        $out[] = '| **Objective** | ' . $na($info['objective'] ?? null) . ' |';
        $out[] = '| **Chair** | ' . $na($info['chair'] ?? null) . ' |';

        $out[] = '';
        $out[] = '## 2. Attendance';
        $out[] = '';
        $out[] = '**Present:**';
        foreach ($att['present'] ?? [] as $p) {
            $out[] = '- **' . $p['name'] . '**'
                . (! empty($p['title']) ? ' — ' . $p['title'] : '')
                . (! empty($p['organization']) ? ' (' . $p['organization'] . ')' : '');
        }
        if (! empty($att['absent'])) {
            $out[] = '';
            $out[] = '**Absent / Apologies:**';
            foreach ($att['absent'] as $p) {
                $out[] = '- **' . $p['name'] . '**' . (! empty($p['reason']) ? ' — ' . $p['reason'] : '');
            }
        }
        if (! empty($att['guests'])) {
            $out[] = '';
            $out[] = '**Guests / External:**';
            foreach ($att['guests'] as $p) {
                $out[] = '- **' . $p['name'] . '**' . (! empty($p['affiliation']) ? ' — ' . $p['affiliation'] : '');
            }
        }

        $out[] = '';
        $out[] = '## 3. Discussion Summary';
        foreach ($s['discussion'] ?? [] as $topic) {
            $out[] = '';
            $out[] = '### ' . $topic['heading'];
            $out[] = '';
            $out[] = $topic['summary'] ?? '';
            if (! empty($topic['key_points'])) {
                $out[] = '';
                $out[] = '**Key points:**';
                foreach ($topic['key_points'] as $kp) {
                    $out[] = '- ' . $kp['point'] . (! empty($kp['raised_by']) ? ' — **' . $kp['raised_by'] . '**' : '');
                }
            }
            if (! empty($topic['questions'])) {
                $out[] = '';
                $out[] = '**Questions raised:**';
                foreach ($topic['questions'] as $q) {
                    $out[] = '- *' . $q['question'] . '*' . (! empty($q['answer']) ? ' — ' . $q['answer'] : '');
                }
            }
            if (! empty($topic['data_points'])) {
                $out[] = '';
                $out[] = '**Data presented:**';
                foreach ($topic['data_points'] as $d) {
                    $out[] = '- ' . $d;
                }
            }
            if (! empty($topic['unresolved'])) {
                $out[] = '';
                $out[] = '**Unresolved / follow-up:**';
                foreach ($topic['unresolved'] as $u) {
                    $out[] = '- ' . $u;
                }
            }
        }

        $out[] = '';
        $out[] = '## 4. Decisions & Resolutions';
        foreach ($s['decisions'] ?? [] as $d) {
            $out[] = '';
            $out[] = '### ' . $d['ref'] . ' — ' . $d['decision'];
            $out[] = '- **Made / approved by:** ' . $na($d['made_by'] ?? null);
            $out[] = '- **Rationale:** ' . $na($d['rationale'] ?? null);
            if (! empty($d['conditions'])) {
                $out[] = '- **Conditions:** ' . $d['conditions'];
            }
            if (! empty($d['impact'])) {
                $out[] = '- **Expected impact:** ' . $d['impact'];
            }
        }
        if (empty($s['decisions'])) {
            $out[] = '';
            $out[] = '_No formal decisions were recorded._';
        }

        $out[] = '';
        $out[] = '## 5. Action Items';
        if (! empty($s['action_items'])) {
            $out[] = '';
            $out[] = '| ID | Action | Owner | Due | Priority | Success criteria |';
            $out[] = '|---|---|---|---|---|---|';
            foreach ($s['action_items'] as $a) {
                $cells = [
                    '**' . $a['ref'] . '**',
                    str_replace('|', '\\|', $a['description'])
                        . (! empty($a['collaborators']) ? ' _(with ' . implode(', ', $a['collaborators']) . ')_' : ''),
                    '**' . $a['owner'] . '**',
                    $na($a['due_date'] ?? null),
                    ucfirst($a['priority'] ?? 'medium'),
                    str_replace('|', '\\|', $na($a['success_criteria'] ?? null)),
                ];
                $out[] = '| ' . implode(' | ', $cells) . ' |';
            }
        } else {
            $out[] = '';
            $out[] = '_No action items were assigned._';
        }

        $out[] = '';
        $out[] = '## 6. Parking Lot & Deferred Items';
        foreach ($s['parking_lot'] ?? [] as $p) {
            $out[] = '- ' . $p['item'] . ' _(' . ($p['type'] ?? 'tabled')
                . (! empty($p['reason']) ? ' — ' . $p['reason'] : '') . ')_';
        }
        if (empty($s['parking_lot'])) {
            $out[] = '_Nothing parked._';
        }

        $out[] = '';
        $out[] = '## 7. Supporting Materials';
        foreach ($s['supporting_materials'] ?? [] as $m) {
            $out[] = '- **' . $m['title'] . '**'
                . (! empty($m['type']) ? ' — ' . $m['type'] : '')
                . (! empty($m['reference']) ? ' (' . $m['reference'] . ')' : '');
        }
        if (empty($s['supporting_materials'])) {
            $out[] = '_No materials referenced._';
        }

        $out[] = '';
        $out[] = '## 8. General Discussion';
        foreach ($s['general_discussion'] ?? [] as $g) {
            $out[] = '- **' . $g['topic'] . '** — ' . $g['note'];
        }
        if (empty($s['general_discussion'])) {
            $out[] = '_Nothing beyond the main agenda._';
        }

        $out[] = '';
        $out[] = '## 9. Next Steps';
        $out[] = '- **Next meeting:** ' . $na($ns['next_meeting'] ?? null);
        if (! empty($ns['checkpoints'])) {
            $out[] = '- **Interim checkpoints:**';
            foreach ($ns['checkpoints'] as $c) {
                $out[] = '  - ' . $c;
            }
        }
        $out[] = '- **Communication plan:** ' . $na($ns['communication_plan'] ?? null);
        if (! empty($ns['monitor'])) {
            $out[] = '- **Items to monitor:**';
            foreach ($ns['monitor'] as $m) {
                $out[] = '  - ' . $m;
            }
        }

        if (! empty($s['quality_notes'])) {
            $out[] = '';
            $out[] = '## Source Quality Notes';
            $out[] = '';
            $out[] = $s['quality_notes'];
        }

        return implode("\n", $out) . "\n";
    }

    // ------------------------------------------------------------------
    // PDF (mpdf — house standard)
    // ------------------------------------------------------------------

    public function pdf(Meeting $meeting): string
    {
        $mpdf = new \Mpdf\Mpdf([
            'format' => 'A4',
            'margin_top' => 18,
            'margin_bottom' => 18,
            'tempDir' => storage_path('app/mpdf'),
        ]);

        $mpdf->SetTitle(($meeting->title ?? 'Meeting Minutes'));
        $mpdf->WriteHTML(view('minutes::export.pdf', ['meeting' => $meeting])->render());

        return $mpdf->OutputBinaryData();
    }

    // ------------------------------------------------------------------
    // DOCX (PHPWord)
    // ------------------------------------------------------------------

    public function docx(Meeting $meeting): string
    {
        $s = $meeting->sections;
        $info = $s['meeting_info'] ?? [];
        $na = fn ($v) => ($v !== null && $v !== '') ? $v : '[Not specified]';

        $word = new PhpWord();
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10.5);
        $word->addTitleStyle(1, ['size' => 18, 'bold' => true]);
        $word->addTitleStyle(2, ['size' => 13, 'bold' => true, 'color' => '1F4E79', 'spaceBefore' => Converter::pointToTwip(10)]);
        $word->addTitleStyle(3, ['size' => 11, 'bold' => true]);

        $tableStyle = ['borderSize' => 4, 'borderColor' => '999999', 'cellMargin' => 60, 'alignment' => JcTable::CENTER];
        $headerRow = ['bgColor' => '1F4E79'];
        $headerFont = ['bold' => true, 'color' => 'FFFFFF'];

        $section = $word->addSection(['marginTop' => 1100, 'marginBottom' => 1100]);
        $section->addTitle($na($info['title'] ?? 'Meeting Minutes'), 1);

        // 1. Meeting information
        $section->addTitle('1. Meeting Information', 2);
        $kv = $section->addTable($tableStyle);
        $time = trim(($info['start_time'] ?? '') . ((isset($info['end_time']) && $info['end_time'] !== '') ? ' – ' . $info['end_time'] : ''));
        foreach ([
            'Title' => $na($info['title'] ?? null),
            'Date' => $na($info['date'] ?? null),
            'Time' => $na($time ?: null) . (! empty($info['duration']) ? " ({$info['duration']})" : ''),
            'Location' => $na($info['location'] ?? null),
            'Type' => $na($info['meeting_type'] ?? null),
            'Objective' => $na($info['objective'] ?? null),
            'Chair' => $na($info['chair'] ?? null),
        ] as $label => $value) {
            $kv->addRow();
            $kv->addCell(2400)->addText($label, ['bold' => true]);
            $kv->addCell(7000)->addText($value);
        }

        // 2. Attendance
        $section->addTitle('2. Attendance', 2);
        $section->addTitle('Present', 3);
        foreach (($s['attendance']['present'] ?? []) as $p) {
            $line = $section->addListItemRun();
            $line->addText($p['name'], ['bold' => true]);
            $line->addText(
                (! empty($p['title']) ? ' — ' . $p['title'] : '')
                . (! empty($p['organization']) ? ' (' . $p['organization'] . ')' : '')
            );
        }
        if (! empty($s['attendance']['absent'])) {
            $section->addTitle('Absent / Apologies', 3);
            foreach ($s['attendance']['absent'] as $p) {
                $line = $section->addListItemRun();
                $line->addText($p['name'], ['bold' => true]);
                $line->addText(! empty($p['reason']) ? ' — ' . $p['reason'] : '');
            }
        }
        if (! empty($s['attendance']['guests'])) {
            $section->addTitle('Guests / External', 3);
            foreach ($s['attendance']['guests'] as $p) {
                $line = $section->addListItemRun();
                $line->addText($p['name'], ['bold' => true]);
                $line->addText(! empty($p['affiliation']) ? ' — ' . $p['affiliation'] : '');
            }
        }

        // 3. Discussion
        $section->addTitle('3. Discussion Summary', 2);
        foreach ($s['discussion'] ?? [] as $topic) {
            $section->addTitle($topic['heading'], 3);
            foreach (preg_split('/\n\s*\n/', $topic['summary'] ?? '') as $para) {
                if (trim($para) !== '') {
                    $section->addText(trim($para));
                }
            }
            foreach ($topic['key_points'] ?? [] as $kp) {
                $line = $section->addListItemRun();
                $line->addText($kp['point']);
                if (! empty($kp['raised_by'])) {
                    $line->addText(' — ');
                    $line->addText($kp['raised_by'], ['bold' => true]);
                }
            }
        }

        // 4. Decisions
        $section->addTitle('4. Decisions & Resolutions', 2);
        foreach ($s['decisions'] ?? [] as $d) {
            $run = $section->addTextRun(['spaceBefore' => Converter::pointToTwip(6)]);
            $run->addText($d['ref'] . ' — ', ['bold' => true]);
            $run->addText($d['decision'], ['bold' => true]);
            $section->addText('Made / approved by: ' . $na($d['made_by'] ?? null));
            $section->addText('Rationale: ' . $na($d['rationale'] ?? null));
            if (! empty($d['conditions'])) {
                $section->addText('Conditions: ' . $d['conditions']);
            }
            if (! empty($d['impact'])) {
                $section->addText('Expected impact: ' . $d['impact']);
            }
        }
        if (empty($s['decisions'])) {
            $section->addText('No formal decisions were recorded.', ['italic' => true]);
        }

        // 5. Action items table
        $section->addTitle('5. Action Items', 2);
        if (! empty($s['action_items'])) {
            $actions = $section->addTable($tableStyle);
            $actions->addRow(null, ['tblHeader' => true]);
            foreach (['ID', 'Action', 'Owner', 'Due', 'Priority', 'Success criteria'] as $heading) {
                $actions->addCell(null, $headerRow)->addText($heading, $headerFont);
            }
            foreach ($s['action_items'] as $a) {
                $actions->addRow();
                $actions->addCell(800)->addText($a['ref'], ['bold' => true]);
                $cell = $actions->addCell(3200);
                $cell->addText($a['description']);
                if (! empty($a['collaborators'])) {
                    $cell->addText('With: ' . implode(', ', $a['collaborators']), ['italic' => true, 'size' => 9]);
                }
                $actions->addCell(1500)->addText($a['owner'], ['bold' => true]);
                $actions->addCell(1300)->addText($na($a['due_date'] ?? null));
                $actions->addCell(1100)->addText(ucfirst($a['priority'] ?? 'medium'));
                $actions->addCell(2500)->addText($na($a['success_criteria'] ?? null));
            }
        } else {
            $section->addText('No action items were assigned.', ['italic' => true]);
        }

        // 6–8 lists
        $section->addTitle('6. Parking Lot & Deferred Items', 2);
        foreach ($s['parking_lot'] ?? [] as $p) {
            $section->addListItem($p['item'] . ' (' . ($p['type'] ?? 'tabled') . (! empty($p['reason']) ? ' — ' . $p['reason'] : '') . ')');
        }
        if (empty($s['parking_lot'])) {
            $section->addText('Nothing parked.', ['italic' => true]);
        }

        $section->addTitle('7. Supporting Materials', 2);
        foreach ($s['supporting_materials'] ?? [] as $m) {
            $section->addListItem($m['title'] . (! empty($m['type']) ? ' — ' . $m['type'] : '') . (! empty($m['reference']) ? ' (' . $m['reference'] . ')' : ''));
        }
        if (empty($s['supporting_materials'])) {
            $section->addText('No materials referenced.', ['italic' => true]);
        }

        $section->addTitle('8. General Discussion', 2);
        foreach ($s['general_discussion'] ?? [] as $g) {
            $line = $section->addListItemRun();
            $line->addText($g['topic'], ['bold' => true]);
            $line->addText(' — ' . $g['note']);
        }
        if (empty($s['general_discussion'])) {
            $section->addText('Nothing beyond the main agenda.', ['italic' => true]);
        }

        // 9. Next steps
        $ns = $s['next_steps'] ?? [];
        $section->addTitle('9. Next Steps', 2);
        $section->addText('Next meeting: ' . $na($ns['next_meeting'] ?? null));
        foreach ($ns['checkpoints'] ?? [] as $c) {
            $section->addListItem($c);
        }
        $section->addText('Communication plan: ' . $na($ns['communication_plan'] ?? null));
        foreach ($ns['monitor'] ?? [] as $m) {
            $section->addListItem('Monitor: ' . $m);
        }

        if (! empty($s['quality_notes'])) {
            $section->addTitle('Source Quality Notes', 2);
            $section->addText($s['quality_notes'], ['italic' => true]);
        }

        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007');
        $tmp = tempnam(sys_get_temp_dir(), 'mn-docx-');
        $writer->save($tmp);
        $binary = file_get_contents($tmp);
        unlink($tmp);

        return $binary;
    }
}
