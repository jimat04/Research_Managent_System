-- Reuse the existing research-area catalog; legacy publications remain unclassified.
ALTER TABLE publications
  ADD COLUMN research_area_id int unsigned DEFAULT NULL,
  ADD KEY idx_publications_research_area (research_area_id),
  ADD CONSTRAINT fk_publications_research_area FOREIGN KEY (research_area_id) REFERENCES research_areas(area_id) ON DELETE RESTRICT;
