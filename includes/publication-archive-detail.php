<?php
// Presentation only: retain the record's IDs for authorization, audit, and document access.
$detailCatalog=publicationCatalog($conn);
$detailMeta=publicationJson($record['publication_metadata_json']??null);
$detailTypeId=(int)($record['publication_type_id']??0);
$detailSubtypeId=(int)($record['publication_subtype_id']??0);
$detailLegacy=!$detailTypeId;
$detailRules=publicationEffectiveRules($detailCatalog,$detailTypeId,$detailSubtypeId,$record);
$detailFields=array_fill_keys($detailRules['fields']??[],true);
$detailValues=[
    'Publication type'=>$detailCatalog['types'][(int)($record['publication_type_id']??0)]['label']??publicationTypeLabel($record),
    'Publication subtype'=>$detailCatalog['subtypes'][(int)($record['publication_subtype_id']??0)]['label']??'Not recorded',
    'Publication scope'=>$detailCatalog['scopes'][(int)($record['publication_scope_id']??0)]['label']??'Not recorded',
    'Publication year'=>publicationYear($record)?:'Not recorded',
];
foreach(publicationFieldCatalog() as $key=>[$label]) {
    if(!$detailLegacy&&!isset($detailFields[$key]))continue;
    if($key==='publication_year')continue;
    if($key==='indexing_evidence'&&!$showPrivateRecordDetails)continue;
    $label=$detailRules['field_labels'][$key]??$label;
    if(isset($detailMeta[$key])&&is_string($detailMeta[$key])&&$detailMeta[$key]!=='')$detailValues[$label]=$detailMeta[$key];
}
if(is_scalar($detailMeta['program_id']??null)&&filter_var((string)$detailMeta['program_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) {
    $program=archiveQuery($conn,'SELECT program_name,program_code FROM programs WHERE program_id=?',[(int)$detailMeta['program_id']])->get_result()->fetch_assoc();
    $detailValues['Program']=$program ? $program['program_code'].' — '.$program['program_name'] : 'Historical program';
}
if($showPrivateRecordDetails&&!empty($detailMeta['indexing_evidence'])&&is_string($detailMeta['indexing_evidence']))$detailValues['Indexing evidence']=$detailMeta['indexing_evidence'];
if(!empty($record['indexing_information']))$detailValues['Indexing classification']=$record['indexing_information'];
if(!empty($record['sdg_alignment']))$detailValues['SDG alignment']=$record['sdg_alignment'];
if(!empty($detailMeta['declared_page_count'])&&is_scalar($detailMeta['declared_page_count']))$detailValues['Manuscript pages (encoder declaration)']=(string)$detailMeta['declared_page_count'];
$detailKeywords=array_filter(publicationJson($record['keywords_json']??null),'is_string');
?>
<?php
$detailAuthors=publicationAuthors($record);
$detailYear=publicationYear($record);
$detailVenue=publicationSource($record);
$detailDoi=publicationDoi((string)($record['doi_identifier']??''));
$detailDoiUrl=preg_match('~^10\.\d{4,9}/[-._;()/:A-Z0-9]+$~iD',$detailDoi) ? 'https://doi.org/'.$detailDoi : '';
$detailType=$detailCatalog['types'][$detailTypeId]['label']??publicationTypeLabel($record);
$detailSubtype=$detailCatalog['subtypes'][$detailSubtypeId]['label']??'';
$detailScope=$detailCatalog['scopes'][(int)($record['publication_scope_id']??0)]['label']??'';
$detailPublicationStatus=($record['status']??'')==='published'?'Published':archiveStatus($record);
$detailPaperPubliclyReleased=false;
if(($record['researcher_category']??'')==='faculty'
    && empty($record['deactivated_at'])
    && (($record['status']??'')==='published'||($record['archival_status']??'')==='archived')) {
    $detailPaperPubliclyReleased=(bool)archiveQuery($conn,"SELECT 1 FROM public_research_releases WHERE source_type='faculty_publication' AND publication_id=? AND release_status='published' AND publication_paper_released=1 AND publication_paper_path IS NOT NULL AND publication_paper_path<>'' LIMIT 1",[(int)$record['publication_id']])->get_result()->fetch_row();
}
$detailRecordFacts=['Publication type'=>$detailType];
if($detailSubtype!=='')$detailRecordFacts['Subtype']=$detailSubtype;
if($detailVenue!=='')$detailRecordFacts['Published in']=$detailVenue;
if($detailYear!=='')$detailRecordFacts['Year']=$detailYear;
if($detailScope!=='')$detailRecordFacts['Scope']=$detailScope;
$detailPrimaryLabels=['Publication type','Publication subtype','Publication scope','Publication year'];
?>
<article class="archive-card archive-publication-record">
    <header class="archive-publication-header">
        <div class="archive-publication-eyebrow"><span class="archive-badge"><?= archiveEscape($detailPublicationStatus) ?></span><span>Publication record</span></div>
        <h1><?= archiveEscape($record['research_title']) ?></h1>
        <p class="archive-publication-byline"><?= $detailAuthors ? archiveEscape(implode(' · ', $detailAuthors)) : 'Author information not recorded' ?></p>
        <dl class="archive-publication-facts"><?php foreach($detailRecordFacts as $label=>$value): ?><div><dt><?= archiveEscape($label) ?></dt><dd><?= archiveEscape($value) ?></dd></div><?php endforeach; ?></dl>
        <div class="archive-publication-identifiers">
            <?php if($detailDoi!==''): ?><span><strong>DOI</strong> <?php if($detailDoiUrl!==''): ?><a href="<?= archiveEscape($detailDoiUrl) ?>" target="_blank" rel="noopener noreferrer"><?= archiveEscape($detailDoi) ?></a><?php else: ?><?= archiveEscape($detailDoi) ?><?php endif; ?></span><?php endif; ?>
            <?php if($detailPaperPubliclyReleased): ?><a class="archive-publication-version-link is-full-text" href="<?= archiveEscape($documentDownloadUrl.'?type=publication&id='.(int)$record['publication_id'].'&mode=viewer') ?>">Read full text</a><?php endif; ?>
            <?php if(publicationHttpUrl((string)($record['publication_url']??''))): ?><a class="archive-publication-version-link" href="<?= archiveEscape($record['publication_url']) ?>" target="_blank" rel="noopener noreferrer">View publication</a><?php endif; ?>
        </div>
        <?php if($detailLegacy): ?><p class="archive-publication-note">This archived record retains its historical classification and metadata.</p><?php endif; ?>
    </header>
    <?php if(!empty($record['abstract_text'])&&($detailLegacy||!empty($detailRules['abstract_visible']))): ?><section class="archive-publication-section" aria-labelledby="publication-abstract-heading"><h2 id="publication-abstract-heading"><?= $detailLegacy?'Historical abstract':'Abstract' ?></h2><p class="archive-publication-abstract"><?= nl2br(archiveEscape($record['abstract_text'])) ?></p></section><?php endif; ?>
    <?php if($detailKeywords&&($detailLegacy||!empty($detailRules['keywords_visible']))): ?><section class="archive-publication-section archive-publication-keywords" aria-labelledby="publication-keywords-heading"><h2 id="publication-keywords-heading">Keywords</h2><ul><?php foreach($detailKeywords as $keyword): ?><li><?= archiveEscape($keyword) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
    <details class="archive-publication-more"><summary>Additional publication metadata</summary><dl class="archive-publication-metadata"><?php foreach($detailValues as $label=>$value): if(in_array($label,$detailPrimaryLabels,true))continue; ?><div><dt><?= archiveEscape($label) ?></dt><dd><?= archiveEscape($value) ?></dd></div><?php endforeach; ?>
        <?php if(publicationHttpUrl((string)($record['publication_url']??''))): ?><div><dt>Publication link</dt><dd><a href="<?= archiveEscape($record['publication_url']) ?>" target="_blank" rel="noopener noreferrer"><?= archiveEscape($record['publication_url']) ?></a></dd></div><?php endif; ?>
        <?php if($showPrivateRecordDetails&&publicationHttpUrl((string)($record['evidence_url']??''))): ?><div><dt>Publication evidence link</dt><dd><a href="<?= archiveEscape($record['evidence_url']) ?>" target="_blank" rel="noopener noreferrer"><?= archiveEscape($record['evidence_url']) ?></a></dd></div><?php endif; ?>
    </dl></details>
    <?php if($showPrivateRecordDetails): ?><section class="archive-publication-provenance" aria-labelledby="publication-provenance-heading"><h2 id="publication-provenance-heading">Repository record</h2><dl class="archive-publication-metadata"><div><dt>Faculty record owner</dt><dd><?= archiveEscape(archiveUserName($conn,$record['researcher_id'])) ?></dd></div><div><dt>Originally recorded</dt><dd><?= archiveEscape($record['created_at']?:'Not recorded') ?></dd></div><div><dt>Last updated</dt><dd><?= archiveEscape($record['updated_at']?:'Not recorded') ?></dd></div><div><dt>Encoded by</dt><dd><?= archiveEscape(archiveUserName($conn,$record['encoded_by'])) ?></dd></div><div><dt>Last modified by</dt><dd><?= archiveEscape(archiveUserName($conn,$record['last_modified_by'])) ?></dd></div><?php if(!empty($record['remarks'])): ?><div class="archive-publication-meta-wide"><dt>Remarks</dt><dd><?= archiveEscape($record['remarks']) ?></dd></div><?php endif; ?></dl></section><?php endif; ?>
</article>
