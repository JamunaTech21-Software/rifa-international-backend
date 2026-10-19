-- The six job categories (plan.md §4.1). Safe to re-run.
INSERT INTO job_categories (slug, name, sort_order, is_active) VALUES
    ('construction', 'Construction', 1, 1),
    ('factory-manufacturing', 'Factory & Manufacturing', 2, 1),
    ('plantation-agriculture', 'Plantation & Agriculture', 3, 1),
    ('hospitality', 'Hospitality', 4, 1),
    ('cleaning-service', 'Cleaning & Service', 5, 1),
    ('logistics-warehouse', 'Logistics & Warehouse', 6, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order);
