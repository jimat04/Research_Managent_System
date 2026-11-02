-- Migration 056: Add custom_engagement_type column to faculty_engagement_records
-- This allows specifying the exact type when "Other Research Engagement" is selected

ALTER TABLE `faculty_engagement_records`
  ADD COLUMN `custom_engagement_type` varchar(255) DEFAULT NULL AFTER `engagement_type`;