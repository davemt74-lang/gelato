-- Fatso starter job data repair for existing installations.
-- Import once after database/20260804_jobs_module.sql.
-- Safe to re-run: existing job records are not overwritten.

INSERT INTO jobs (
  organization_id, public_id, slug, title, department, location_name,
  employment_type, schedule_text, pay_range, summary, description,
  responsibilities_json, requirements_json, benefits_json,
  status, sort_order, published_at, created_by, updated_by
)
SELECT
  o.id,
  'job-server',
  'server',
  'Server',
  'Front of House',
  'Phoenix, Arizona',
  'Full-time or part-time',
  'Day, evening, and weekend shifts',
  'Hourly wage plus tips',
  'Guide guests through the menu, deliver accurate service, and maintain a welcoming dining room.',
  'Servers create a clear, friendly guest experience while protecting menu accuracy and food-safety communication.',
  JSON_ARRAY(
    'Learn the complete menu and current specials',
    'Enter orders accurately and communicate modifications',
    'Follow allergen escalation and service procedures',
    'Maintain clean, stocked service areas'
  ),
  JSON_ARRAY(
    'Reliable attendance',
    'Clear communication',
    'Ability to work standing shifts',
    'Food Handler Card or ability to obtain one'
  ),
  JSON_ARRAY(
    'Structured menu training',
    'Position certification path',
    'Employee meal benefits'
  ),
  'published',
  10,
  CURRENT_TIMESTAMP(6),
  (SELECT om.user_id FROM organization_memberships om WHERE om.organization_id = o.id ORDER BY om.id ASC LIMIT 1),
  (SELECT om.user_id FROM organization_memberships om WHERE om.organization_id = o.id ORDER BY om.id ASC LIMIT 1)
FROM organizations o
WHERE NOT EXISTS (
  SELECT 1 FROM jobs j
  WHERE j.organization_id = o.id
    AND (j.public_id = 'job-server' OR j.slug = 'server')
);

INSERT INTO jobs (
  organization_id, public_id, slug, title, department, location_name,
  employment_type, schedule_text, pay_range, summary, description,
  responsibilities_json, requirements_json, benefits_json,
  status, sort_order, published_at, created_by, updated_by
)
SELECT
  o.id,
  'job-pizza-cook',
  'pizza-cook',
  'Pizza Cook',
  'Kitchen',
  'Phoenix, Arizona',
  'Full-time or part-time',
  'Evening and weekend availability preferred',
  'Competitive hourly wage',
  'Prepare pizzas to specification, manage oven timing, and protect recipe consistency.',
  'Pizza cooks own dough, sauce, cheese, topping, and oven accuracy during high-volume service.',
  JSON_ARRAY(
    'Build pizzas from approved recipes',
    'Verify modifiers and allergen-sensitive tickets',
    'Maintain station sanitation and ingredient rotation',
    'Coordinate timing with expo and the line'
  ),
  JSON_ARRAY(
    'Kitchen experience preferred',
    'Ability to lift restaurant supplies',
    'Strong attention to detail',
    'Food Handler Card or ability to obtain one'
  ),
  JSON_ARRAY(
    'Paid menu training',
    'Kitchen certification pathway',
    'Growth into trainer or shift lead'
  ),
  'published',
  20,
  CURRENT_TIMESTAMP(6),
  (SELECT om.user_id FROM organization_memberships om WHERE om.organization_id = o.id ORDER BY om.id ASC LIMIT 1),
  (SELECT om.user_id FROM organization_memberships om WHERE om.organization_id = o.id ORDER BY om.id ASC LIMIT 1)
FROM organizations o
WHERE NOT EXISTS (
  SELECT 1 FROM jobs j
  WHERE j.organization_id = o.id
    AND (j.public_id = 'job-pizza-cook' OR j.slug = 'pizza-cook')
);
