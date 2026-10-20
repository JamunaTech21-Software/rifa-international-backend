-- The 12 starting job listings (same as the frontend's src/data/jobs.js). Runs after job_categories.sql.
-- Only fills an EMPTY jobs table, so re-running never brings back jobs an admin deleted or overwrites edits.
INSERT INTO jobs (slug, title, employer_type, job_category_id, location, salary_min, salary_max, sort_order)
SELECT v.slug, v.title, v.employer_type, c.id, v.location, v.salary_min, v.salary_max, v.sort_order
FROM (
    SELECT 'construction-worker' AS slug, 'Construction Worker' AS title, 'Construction Company' AS employer_type, 'construction' AS category, 'Kuala Lumpur' AS location, 1800 AS salary_min, 2200 AS salary_max, 1 AS sort_order
    UNION ALL SELECT 'production-operator', 'Production Operator', 'Factory', 'factory-manufacturing', 'Johor', 1700, 2000, 2
    UNION ALL SELECT 'palm-oil-plantation-worker', 'Palm Oil Plantation Worker', 'Plantation', 'plantation-agriculture', 'Sabah / Sarawak', 1600, 2000, 3
    UNION ALL SELECT 'kitchen-helper', 'Kitchen Helper', 'Hotel / Restaurant', 'hospitality', 'Kuala Lumpur', 1600, 2000, 4
    UNION ALL SELECT 'cleaning-staff', 'Cleaning Staff', 'Cleaning Service', 'cleaning-service', 'Kuala Lumpur', 1600, 1900, 5
    UNION ALL SELECT 'warehouse-assistant', 'Warehouse Assistant', 'Logistics', 'logistics-warehouse', 'Johor', 1800, 2200, 6
    UNION ALL SELECT 'scaffolder', 'Scaffolder', 'Construction Company', 'construction', 'Selangor', 1900, 2300, 7
    UNION ALL SELECT 'machine-operator', 'Machine Operator', 'Electronics Factory', 'factory-manufacturing', 'Penang', 1700, 2100, 8
    UNION ALL SELECT 'farm-worker', 'Farm Worker', 'Agriculture Farm', 'plantation-agriculture', 'Johor', 1600, 1900, 9
    UNION ALL SELECT 'housekeeping-attendant', 'Housekeeping Attendant', 'Hotel', 'hospitality', 'Selangor', 1600, 1900, 10
    UNION ALL SELECT 'office-cleaner', 'Office Cleaner', 'Facility Management', 'cleaning-service', 'Penang', 1500, 1800, 11
    UNION ALL SELECT 'forklift-driver', 'Forklift Driver', 'Logistics', 'logistics-warehouse', 'Kuala Lumpur', 1900, 2400, 12
) AS v
JOIN job_categories c ON c.slug = v.category
WHERE NOT EXISTS (SELECT 1 FROM jobs);
