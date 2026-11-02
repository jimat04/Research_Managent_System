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
    $label=$detailRules['field_labels'][$key]??$label;
    if(isset($detailMeta[$key])&&is_string($detailMeta[$key])&&$detailMeta[$key]!=='')$detailValues[$label]=$detailMeta[$key];
}
if(is_scalar($detailMeta['program_id']??null)&&filter_var((string)$detailMeta['program_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) {
    $program=archiveQuery($conn,'SELECT program_name,program_code FROM programs WHERE program_id=?',[(int)$detailMeta['program_id']])->get_result()->fetch_assoc();
    $detailValues['Program']=$program ? $program['program_code'].' — '.$program['program_name'] : 'Historical program';
}
if(!empty($detailMeta['indexing_evidence'])&&is_string($detailMeta['indexing_evidence']))$detailValues['Indexing evidence']=$detailMeta['indexing_evidence'];
if(!empty($record['indexing_information']))$detailValues['Indexing classification']=$record['indexing_information'];
if(!empty($record['sdg_alignment']))$detailValues['SDG alignment']=$record['sdg_alignment'];
if(!empty($detailMeta['declared_page_count'])&&is_scalar($detailMeta['declared_page_count']))$detailValues['Manuscript pages (encoder declaration)']=(string)$detailMeta['declared_page_count'];
$detailKeywords=array_filter(publicationJson($record['keywords_json']??null),'is_string');
?>
<section class="archive-card archive-publication-information"><h2>Publication information</h2><?php if($detailLegacy): ?><p class="archive-muted">This record retains its historical classification and metadata. Reclassification is available when editing the record.</p><?php endif; ?>
<section class="archive-publication-authors" aria-labelledby="publication-authors-heading"><div class="archive-publication-authors-heading"><div><h3 id="publication-authors-heading"><?= archiveEscape(($detailLegacy?'Authors / contributors':($detailRules['contributor_label']??'Authors / contributors')).' in publication order') ?></h3><p>Listed in the same sequence as the published work.</p></div><span><?= count(publicationAuthors($record)) ?> <?= count(publicationAuthors($record))===1?'contributor':'contributors' ?></span></div>
<?php $detailAuthors=publicationAuthors($record); if($detailAuthors): ?><ol class="archive-publication-author-list"><?php foreach($detailAuthors as $index=>$name): ?><li><span class="archive-publication-author-number" aria-hidden="true"><?= str_pad((string)($index+1),2,'0',STR_PAD_LEFT) ?></span><span class="archive-publication-author-name"><?= archiveEscape($name) ?></span></li><?php endforeach; ?></ol><?php else: ?><p class="archive-publication-authors-empty">No author names are recorded.</p><?php endif; ?></section>
<dl class="archive-grid archive-detail"><?php foreach($detailValues as $label=>$value): ?><div><dt><?= archiveEscape($label) ?></dt><dd><?= archiveEscape($value) ?></dd></div><?php endforeach; ?>
<?php foreach(['publication_url'=>'Publication link','evidence_url'=>'Publication evidence link'] as $key=>$label): if(!publicationHttpUrl((string)($record[$key]??'')))continue; ?><div><dt><?= archiveEscape($label) ?></dt><dd><a href="<?= archiveEscape($record[$key]) ?>" target="_blank" rel="noopener noreferrer"><?= archiveEscape($record[$key]) ?></a></dd></div><?php endforeach; ?></dl>
<?php if(!empty($record['abstract_text'])&&($detailLegacy||!empty($detailRules['abstract_visible']))): ?><h3><?= $detailLegacy?'Historical abstract':'Abstract' ?></h3><p><?= nl2br(archiveEscape($record['abstract_text'])) ?></p><?php endif; ?>
<?php if($detailKeywords&&($detailLegacy||!empty($detailRules['keywords_visible']))): ?><h3><?= $detailLegacy?'Historical keywords':'Keywords' ?></h3><p><?= archiveEscape(implode('; ',$detailKeywords)) ?></p><?php endif; ?>
</section>
