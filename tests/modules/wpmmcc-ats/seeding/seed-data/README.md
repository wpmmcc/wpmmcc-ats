# Seed Data Files

This directory contains individual JSON files for custom seeding of WordPress plugins that don't have official import data.

## Directory Structure

```
seed-data/
├── README.md                       # This file
├── academy.json                    # Plan A - Academy LMS
├── masterstudy-lms.json            # Plan A - MasterStudy LMS
├── classified-listing.json         # Plan A - Classified Listing
├── easy-property-listings.json     # Plan A - Easy Property Listings
├── cooked.json                     # Plan A - Cooked Recipe
├── envira-gallery-lite.json        # Plan A - Envira Gallery
├── site-reviews.json               # Plan A - Site Reviews
├── testimonial-free.json           # Plan A - Testimonial Free
├── directorist.json                # Plan B - Directorist
├── the-events-calendar.json        # Plan B - The Events Calendar
├── wp-job-manager.json             # Plan B - WP Job Manager
├── essential-real-estate.json      # Plan B - Essential Real Estate
├── asgaros-forum.json              # Plan B - Asgaros Forum
├── delicious-recipes.json          # Plan B - Delicious Recipes
├── foogallery.json                 # Plan B - FooGallery
├── portfolio-post-type.json        # Plan B - Portfolio Post Type
├── seriously-simple-podcasting.json # Plan B - Seriously Simple Podcasting
├── wp-easycart.json                # Plan C - WP EasyCart
├── wpforo.json                     # Plan C - wpForo
├── estatik.json                    # Plan C - Estatik
├── wp-recipe-maker.json            # Plan C - WP Recipe Maker
├── simple-job-board.json           # Plan C - Simple Job Board
├── ultimate-faqs.json              # Plan C - Ultimate FAQs
├── wp-customer-reviews.json        # Plan C - WP Customer Reviews
├── storeengine.json                # Plan D - StoreEngine
├── hivepress.json                  # Plan D - HivePress
├── geodirectory.json               # Plan D - GeoDirectory
├── forumwp.json                    # Plan D - ForumWP
├── propertyhive.json               # Plan D - PropertyHive
├── events-manager.json             # Plan D - Events Manager
├── wp-job-openings.json            # Plan D - WP Job Openings
├── give.json                       # Plan D - GiveWP
└── podlove.json                    # Plan D - Podlove Podcast Publisher
```

## JSON Schema

Each seed data file follows this structure:

```json
{
    "_meta": {
        "plugin_slug": "plugin-slug",
        "plugin_name": "Plugin Name",
        "plan": "A|B|C|D",
        "version": "1.0.0",
        "description": "Brief description",
        "note": "Optional notes about special handling"
    },
    "taxonomies": {
        "taxonomy_name": [
            {"name": "Term Name", "slug": "term-slug"},
            {"name": "Parent Term", "slug": "parent", "children": [
                {"name": "Child Term", "slug": "child"}
            ]}
        ]
    },
    "posts": {
        "post_type_name": [
            {
                "post_title": "Title",
                "post_content": "<p>HTML content</p>",
                "post_excerpt": "Short description",
                "post_status": "publish",
                "meta": {
                    "_meta_key": "value"
                },
                "taxonomy": {
                    "taxonomy_name": ["term-slug"]
                }
            }
        ]
    },
    "custom_tables": {
        // For plugins using custom database tables
    }
}
```

## Reference Syntax

Use `{{post_type:index}}` to reference other posts:

```json
{
    "meta": {
        "_course_id": "{{academy_courses:0}}",
        "_thumbnail_id": "{{attachment:0}}"
    }
}
```

This will be resolved at seeding time to the actual post ID.

## Content Guidelines

1. **Language**: English (matching official data sources)
2. **Length**: Minimal but meaningful - enough to represent the business case
3. **Variety**: Mix of different content types within each plugin
4. **Realism**: Content should resemble real-world usage
5. **Meta fields**: Include key plugin-specific meta fields based on scanner output

## Plan Distribution

| Plan | Plugins |
|------|---------|
| A | academy, masterstudy-lms, classified-listing, easy-property-listings, cooked, envira-gallery-lite, site-reviews, testimonial-free |
| B | directorist, the-events-calendar, wp-job-manager, essential-real-estate, asgaros-forum, delicious-recipes, foogallery, portfolio-post-type, seriously-simple-podcasting |
| C | wp-easycart, wpforo, estatik, wp-recipe-maker, simple-job-board, ultimate-faqs, wp-customer-reviews |
| D | storeengine, hivepress, geodirectory, forumwp, propertyhive, events-manager, wp-job-openings, give, podlove |

## Special Cases

### Custom Tables
Some plugins (wpforo, asgaros-forum) use custom database tables instead of WordPress post types. These are documented with `custom_tables` key and require special seeding logic.

### Media/Attachments
Gallery plugins (envira-gallery-lite, foogallery) require images to be uploaded separately. The seed data defines gallery structure and metadata only.

### Reviews/Comments
Some plugins (wp-customer-reviews) store data as comments rather than posts. These use a different structure with `reviews` key.

## Usage

The seed data files are consumed by `seed-content.php` during Phase 2 of the seeding workflow:

```bash
cd /usr/local/var/www
wp eval-file /Users/zhangxiao/wptsall-dev/dev-tools/seeding/seed-content.php A
```

## Maintenance

When adding or updating seed data:

1. Follow the JSON schema above
2. Include realistic English content
3. Include key meta fields from scanner output
4. Test the seeding script after changes
5. Update the file count in this README if adding new plugins

---

**Last Updated**: 2026-01-27
**Total Plugins**: 34 custom seeding plugins
