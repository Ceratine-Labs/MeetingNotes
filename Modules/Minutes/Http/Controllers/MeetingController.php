<?php

namespace Modules\Minutes\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Minutes\Jobs\GenerateMinutesJob;
use Modules\Minutes\Models\Meeting;
use Modules\Minutes\Services\ExtractionException;
use Modules\Minutes\Services\TranscriptExtractor;

class MeetingController extends Controller
{
    public function index(Request $request)
    {
        $meetings = Meeting::query()
            ->when($request->filled('q'), fn ($q) => $q->search($request->string('q'), ['title']))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->withCount(['decisions', 'actionItems'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('minutes::index', compact('meetings'));
    }

    public function create()
    {
        return view('minutes::create', [
            'supported' => TranscriptExtractor::SUPPORTED,
        ]);
    }

    public function store(Request $request, TranscriptExtractor $extractor)
    {
        $validated = $request->validate([
            'mode' => ['required', 'in:paste,upload'],
            'pasted_text' => ['required_if:mode,paste', 'nullable', 'string', 'min:40', 'max:2000000'],
            'file' => ['required_if:mode,upload', 'nullable', 'file', 'max:20480'],
            'title' => ['nullable', 'string', 'max:255'],
            'meeting_date' => ['nullable', 'date'],
        ], [
            'pasted_text.min' => 'That is too short to be meeting content — paste the full transcript or notes.',
        ]);

        $filePath = null;
        $filename = null;
        $mime = null;

        if ($validated['mode'] === 'upload') {
            try {
                $extracted = $extractor->extract($request->file('file'));
            } catch (ExtractionException $e) {
                return back()->withErrors(['file' => $e->getMessage()])->withInput();
            }

            $text = $extracted['text'];
            $mime = $extracted['mime'];
            $filename = $request->file('file')->getClientOriginalName();
            $filePath = $request->file('file')->store('transcripts');
        } else {
            $text = trim($validated['pasted_text']);
        }

        $meeting = Meeting::query()->create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? null,
            'meeting_date' => $validated['meeting_date'] ?? null,
            'source_type' => $validated['mode'] === 'upload' ? 'file' : 'paste',
            'status' => Meeting::STATUS_PROCESSING,
            'progress_stage' => 'queued',
        ]);

        $meeting->transcript()->create([
            'raw_text' => $text,
            'original_filename' => $filename,
            'file_path' => $filePath,
            'mime' => $mime,
            'word_count' => str_word_count(strip_tags($text)),
            'token_estimate' => (int) ceil(mb_strlen($text) / 4),
        ]);

        GenerateMinutesJob::dispatch($meeting->id);

        return redirect()->route('minutes.show', $meeting);
    }

    public function show(Meeting $meeting)
    {
        $meeting->load(['transcript', 'decisions', 'actionItems']);

        return view('minutes::show', compact('meeting'));
    }

    /**
     * Polled by the workspace while processing.
     */
    public function status(Meeting $meeting)
    {
        return response()->json([
            'status' => $meeting->status,
            'progress_stage' => $meeting->progress_stage,
            'error' => $meeting->error,
            'regen_section' => $meeting->regen_section,
            'has_proposal' => $meeting->section_proposal !== null,
        ]);
    }

    public function retry(Meeting $meeting)
    {
        abort_unless($meeting->status === Meeting::STATUS_FAILED, 422, 'Only failed meetings can be retried.');

        $meeting->update([
            'status' => Meeting::STATUS_PROCESSING,
            'progress_stage' => 'queued',
            'error' => null,
        ]);

        GenerateMinutesJob::dispatch($meeting->id);

        return redirect()->route('minutes.show', $meeting);
    }

    public function destroy(Meeting $meeting)
    {
        if ($meeting->transcript?->file_path) {
            Storage::delete($meeting->transcript->file_path);
        }

        $meeting->delete();

        return redirect()->route('minutes.index')->with('status', 'Minutes deleted.');
    }
}
