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

    // Staff creates a custom citation for a thesis
    public function store(Request $request, $thesisId)
    {
        $thesis = Thesis::findOrFail($thesisId);

        $request->validate([
            'format_type'   => 'required|in:APA,MLA',
            'citation_text' => 'required|string',
        ]);

        $citation = Citation::create([
            'thesis_id'     => $thesis->id,
            'format_type'   => $request->format_type,
            'citation_text' => $request->citation_text,
            'created_by'    => $request->user()->id,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'create_citation',
            'target_type' => 'thesis',
            'target_id'   => $thesis->id,
            'description' => "{$request->user()->name} created {$request->format_type} citation for: {$thesis->title}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json($citation, 201);
    }

    // Auto-generate APA and MLA from thesis metadata
    public function generate($thesisId)
    {
        $thesis = Thesis::with('category')->findOrFail($thesisId);

        $authors = $thesis->authors;
        $year    = $thesis->year_published;
        $title   = $thesis->title;
        $school  = 'Mater Dei College';

        $apa = "{$authors} ({$year}). {$title} [Unpublished thesis]. {$school}.";
        $mla = "{$authors}. \"{$title}.\" Unpublished thesis, {$school}, {$year}.";

        // Save generated citations if they don't exist yet
        Citation::firstOrCreate(
            ['thesis_id' => $thesis->id, 'format_type' => 'APA'],
            ['citation_text' => $apa, 'created_by' => null]
        );

        Citation::firstOrCreate(
            ['thesis_id' => $thesis->id, 'format_type' => 'MLA'],
            ['citation_text' => $mla, 'created_by' => null]
        );

        return response()->json([
            'APA' => $apa,
            'MLA' => $mla,
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

    // Update a custom citation
    public function update(Request $request, $thesisId, $citationId)
    {
        $citation = Citation::where('thesis_id', $thesisId)
            ->findOrFail($citationId);

        $request->validate([
            'citation_text' => 'required|string',
        ]);

        $citation->update(['citation_text' => $request->citation_text]);

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