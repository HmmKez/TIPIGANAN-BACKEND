<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Citation;
use App\Models\CitationLog;
use App\Models\Thesis;
use Illuminate\Http\Request;

class CitationController extends Controller
{
    // Get all citations for a thesis — public
    public function index($thesisId)
    {
        $thesis    = Thesis::findOrFail($thesisId);
        $citations = Citation::where('thesis_id', $thesisId)->get();

        return response()->json($citations);
    }

    // Auto-generate APA and MLA from thesis metadata
    public function generate($thesisId)
    {
        $thesis = Thesis::with('category')->findOrFail($thesisId);

        $authors = $thesis->authors;
        $year    = $thesis->year_published;
        $title   = $thesis->title;
        $school  = 'Mater Dei College';

        $defaultApa = "{$authors} ({$year}). {$title} [Unpublished thesis]. {$school}.";
        $defaultMla = "{$authors}. \"{$title}.\" Unpublished thesis, {$school}, {$year}.";

        // firstOrCreate only inserts the default text the first time a
        // format is requested — if staff have since customized the citation
        // (created_by = staff id), that row already exists and its saved
        // citation_text (not a freshly recomputed default) must win here.
        $apaCitation = Citation::firstOrCreate(
            ['thesis_id' => $thesis->id, 'format_type' => 'APA'],
            ['citation_text' => $defaultApa, 'created_by' => null]
        );

        $mlaCitation = Citation::firstOrCreate(
            ['thesis_id' => $thesis->id, 'format_type' => 'MLA'],
            ['citation_text' => $defaultMla, 'created_by' => null]
        );

        return response()->json([
            'APA' => $apaCitation->citation_text,
            'MLA' => $mlaCitation->citation_text,
        ]);
    }

    // Log when a user copies a citation — powers Most Cited
    public function logCitation(Request $request, $thesisId)
    {
        $request->validate([
            'citation_id' => 'nullable|exists:citations,id',
        ]);

        CitationLog::create([
            'thesis_id'   => $thesisId,
            'user_id'     => $request->user()->id,
            'citation_id' => $request->citation_id,
            'cited_at'    => now(),
        ]);

        return response()->json(['message' => 'Citation logged.']);
    }

    // Edit the text of the auto-generated APA or MLA citation
    public function update(Request $request, $thesisId, $citationId)
    {
        $citation = Citation::where('thesis_id', $thesisId)
            ->findOrFail($citationId);

        $request->validate([
            'citation_text' => 'required|string',
        ]);

        $citation->update([
            'citation_text' => $request->citation_text,
            'created_by'    => $request->user()->id,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'edit_citation',
            'target_type' => 'thesis',
            'target_id'   => $thesisId,
            'description' => "{$request->user()->name} edited the {$citation->format_type} citation for thesis #{$thesisId}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($citation);
    }

    // Delete a citation
    public function destroy($thesisId, $citationId)
    {
        $citation = Citation::where('thesis_id', $thesisId)
            ->findOrFail($citationId);

        $citation->delete();

        return response()->json(['message' => 'Citation deleted.']);
    }
}