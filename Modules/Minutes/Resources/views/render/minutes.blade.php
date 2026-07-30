{{--
     Canonical minutes render. $s = validated sections struct.
     The LLM NEVER produces this HTML — it fills the struct, this
     template renders it. That is what makes every minutes record
     structurally identical. Changes here re-render on next
     (re)generation; use a data migration to re-render historic rows.
--}}
@php
    $info = $s['meeting_info'] ?? [];
    $att = $s['attendance'] ?? [];
    $ns = $s['next_steps'] ?? [];
    $fmt = fn ($v) => $v !== null && $v !== '' ? e($v) : '<span class="mn-na">[Not specified]</span>';
@endphp
<article class="mn-minutes">

    <section class="mn-section" data-section="meeting_info">
        <h2>1. Meeting Information</h2>
        <table class="mn-kv">
            <tr><th>Title</th><td>{!! $fmt($info['title'] ?? null) !!}</td></tr>
            <tr><th>Date</th><td>{!! $fmt($info['date'] ?? null) !!}</td></tr>
            <tr><th>Time</th><td>{!! $fmt(trim(($info['start_time'] ?? '') . (isset($info['end_time']) && $info['end_time'] !== '' ? ' – ' . $info['end_time'] : '')) ?: null) !!}
                @if (!empty($info['duration']) && !str_contains($info['duration'], 'Not specified')) ({{ $info['duration'] }}) @endif</td></tr>
            <tr><th>Location</th><td>{!! $fmt($info['location'] ?? null) !!}</td></tr>
            <tr><th>Type</th><td>{!! $fmt($info['meeting_type'] ?? null) !!}</td></tr>
            <tr><th>Objective</th><td>{!! $fmt($info['objective'] ?? null) !!}</td></tr>
            <tr><th>Chair</th><td>{!! $fmt($info['chair'] ?? null) !!}</td></tr>
        </table>
    </section>

    <section class="mn-section" data-section="attendance">
        <h2>2. Attendance</h2>
        <h3>Present</h3>
        @if (!empty($att['present']))
            <ul>
                @foreach ($att['present'] as $p)
                    <li><strong>{{ $p['name'] }}</strong>@if (!empty($p['title'])) — {{ $p['title'] }}@endif @if (!empty($p['organization'])) ({{ $p['organization'] }})@endif</li>
                @endforeach
            </ul>
        @else
            <p class="mn-na">[Not specified]</p>
        @endif
        @if (!empty($att['absent']))
            <h3>Absent / Apologies</h3>
            <ul>
                @foreach ($att['absent'] as $p)
                    <li><strong>{{ $p['name'] }}</strong>@if (!empty($p['reason'])) — {{ $p['reason'] }}@endif</li>
                @endforeach
            </ul>
        @endif
        @if (!empty($att['guests']))
            <h3>Guests / External</h3>
            <ul>
                @foreach ($att['guests'] as $p)
                    <li><strong>{{ $p['name'] }}</strong>@if (!empty($p['affiliation'])) — {{ $p['affiliation'] }}@endif</li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="mn-section" data-section="discussion">
        <h2>3. Discussion Summary</h2>
        @forelse ($s['discussion'] ?? [] as $topic)
            <div class="mn-topic">
                <h3>{{ $topic['heading'] }}</h3>
                @foreach (preg_split('/\n\s*\n/', $topic['summary'] ?? '') as $para)
                    @if (trim($para) !== '')<p>{{ trim($para) }}</p>@endif
                @endforeach
                @if (!empty($topic['key_points']))
                    <h4>Key points</h4>
                    <ul>
                        @foreach ($topic['key_points'] as $kp)
                            <li>{{ $kp['point'] }}@if (!empty($kp['raised_by'])) — <strong>{{ $kp['raised_by'] }}</strong>@endif</li>
                        @endforeach
                    </ul>
                @endif
                @if (!empty($topic['questions']))
                    <h4>Questions raised</h4>
                    <ul>
                        @foreach ($topic['questions'] as $q)
                            <li><em>{{ $q['question'] }}</em>@if (!empty($q['answer'])) — {{ $q['answer'] }}@endif</li>
                        @endforeach
                    </ul>
                @endif
                @if (!empty($topic['data_points']))
                    <h4>Data presented</h4>
                    <ul>@foreach ($topic['data_points'] as $d)<li>{{ $d }}</li>@endforeach</ul>
                @endif
                @if (!empty($topic['unresolved']))
                    <h4>Unresolved / follow-up</h4>
                    <ul>@foreach ($topic['unresolved'] as $u)<li>{{ $u }}</li>@endforeach</ul>
                @endif
            </div>
        @empty
            <p class="mn-na">No discussion topics captured.</p>
        @endforelse
    </section>

    <section class="mn-section" data-section="decisions">
        <h2>4. Decisions &amp; Resolutions</h2>
        @forelse ($s['decisions'] ?? [] as $d)
            <div class="mn-decision">
                <h3><strong>{{ $d['ref'] }}</strong> — {{ $d['decision'] }}</h3>
                <table class="mn-kv">
                    <tr><th>Made / approved by</th><td>{!! $fmt($d['made_by'] ?? null) !!}</td></tr>
                    <tr><th>Rationale</th><td>{!! $fmt($d['rationale'] ?? null) !!}</td></tr>
                    @if (!empty($d['conditions']))<tr><th>Conditions</th><td>{{ $d['conditions'] }}</td></tr>@endif
                    @if (!empty($d['impact']))<tr><th>Expected impact</th><td>{{ $d['impact'] }}</td></tr>@endif
                </table>
            </div>
        @empty
            <p class="mn-na">No formal decisions were recorded.</p>
        @endforelse
    </section>

    <section class="mn-section" data-section="action_items">
        <h2>5. Action Items</h2>
        @if (!empty($s['action_items']))
            <table class="mn-actions">
                <thead>
                    <tr><th>ID</th><th>Action</th><th>Owner</th><th>Due</th><th>Priority</th><th>Success criteria</th><th>Dependencies</th></tr>
                </thead>
                <tbody>
                    @foreach ($s['action_items'] as $a)
                        <tr>
                            <td><strong>{{ $a['ref'] }}</strong></td>
                            <td>{{ $a['description'] }}
                                @if (!empty($a['collaborators']))<br><small>With: {{ implode(', ', $a['collaborators']) }}</small>@endif
                            </td>
                            <td><strong>{{ $a['owner'] }}</strong></td>
                            <td>{!! $fmt($a['due_date'] ?? null) !!}</td>
                            <td class="mn-priority-{{ $a['priority'] ?? 'medium' }}">{{ ucfirst($a['priority'] ?? 'medium') }}</td>
                            <td>{!! $fmt($a['success_criteria'] ?? null) !!}</td>
                            <td>{!! $fmt($a['dependencies'] ?? null) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="mn-na">No action items were assigned.</p>
        @endif
    </section>

    <section class="mn-section" data-section="parking_lot">
        <h2>6. Parking Lot &amp; Deferred Items</h2>
        @if (!empty($s['parking_lot']))
            <ul>
                @foreach ($s['parking_lot'] as $p)
                    <li>{{ $p['item'] }}
                        <em>({{ ['tabled' => 'tabled', 'research' => 'needs research', 'off_topic' => 'off-topic capture'][$p['type'] ?? 'tabled'] ?? $p['type'] }}@if (!empty($p['reason'])) — {{ $p['reason'] }}@endif)</em>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mn-na">Nothing parked.</p>
        @endif
    </section>

    <section class="mn-section" data-section="supporting_materials">
        <h2>7. Supporting Materials</h2>
        @if (!empty($s['supporting_materials']))
            <ul>
                @foreach ($s['supporting_materials'] as $m)
                    <li><strong>{{ $m['title'] }}</strong>@if (!empty($m['type'])) — {{ $m['type'] }}@endif @if (!empty($m['reference'])) ({{ $m['reference'] }})@endif</li>
                @endforeach
            </ul>
        @else
            <p class="mn-na">No materials referenced.</p>
        @endif
    </section>

    <section class="mn-section" data-section="general_discussion">
        <h2>8. General Discussion</h2>
        @if (!empty($s['general_discussion']))
            <ul>
                @foreach ($s['general_discussion'] as $g)
                    <li><strong>{{ $g['topic'] }}</strong> — {{ $g['note'] }}</li>
                @endforeach
            </ul>
        @else
            <p class="mn-na">Nothing beyond the main agenda.</p>
        @endif
    </section>

    <section class="mn-section" data-section="next_steps">
        <h2>9. Next Steps</h2>
        <table class="mn-kv">
            <tr><th>Next meeting</th><td>{!! $fmt($ns['next_meeting'] ?? null) !!}</td></tr>
            <tr><th>Interim checkpoints</th><td>
                @if (!empty($ns['checkpoints']))
                    <ul class="mb-0">@foreach ($ns['checkpoints'] as $c)<li>{{ $c }}</li>@endforeach</ul>
                @else
                    <span class="mn-na">[Not specified]</span>
                @endif
            </td></tr>
            <tr><th>Communication plan</th><td>{!! $fmt($ns['communication_plan'] ?? null) !!}</td></tr>
            <tr><th>Items to monitor</th><td>
                @if (!empty($ns['monitor']))
                    <ul class="mb-0">@foreach ($ns['monitor'] as $m)<li>{{ $m }}</li>@endforeach</ul>
                @else
                    <span class="mn-na">[Not specified]</span>
                @endif
            </td></tr>
        </table>
    </section>

    @if (!empty($s['quality_notes']))
        <section class="mn-section mn-quality" data-section="quality_notes">
            <h2>Source Quality Notes</h2>
            <p>{{ $s['quality_notes'] }}</p>
        </section>
    @endif

</article>
