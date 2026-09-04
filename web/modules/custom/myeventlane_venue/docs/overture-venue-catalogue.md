# Overture venue catalogue

MyEventLane stores only a reviewed Australian extract of Overture Places. The
organiser-facing request never queries Overture, Google, or Apple directly.

Overture Places is published under permissive source licences. Keep the source
columns in the extract and retain the organiser-facing attribution. Review the
current Overture licensing and release notes before each refresh:

- https://docs.overturemaps.org/guides/places/
- https://docs.overturemaps.org/attribution/

## Required CSV

The importer requires these columns:

```text
id,name,address,locality,postcode,region,country,latitude,longitude,website,phone,email,socials,confidence,source_dataset,source_updated
```

`socials` must be a JSON array of HTTP or HTTPS URLs. The first slice rejects
non-Australian rows and invalid coordinates. It does not import descriptions,
photos, reviews, ratings, capacity, parking, or accessibility claims.

An example DuckDB projection from an official Overture release is:

```sql
INSTALL spatial;
INSTALL httpfs;
LOAD spatial;
LOAD httpfs;
SET s3_region = 'us-west-2';

COPY (
  SELECT
    id,
    names.primary AS name,
    addresses[1].freeform AS address,
    addresses[1].locality AS locality,
    addresses[1].postcode AS postcode,
    addresses[1].region AS region,
    addresses[1].country AS country,
    ST_Y(geometry) AS latitude,
    ST_X(geometry) AS longitude,
    websites[1] AS website,
    phones[1] AS phone,
    emails[1] AS email,
    to_json(socials) AS socials,
    confidence,
    sources[1].dataset AS source_dataset,
    sources[1].update_time AS source_updated
  FROM read_parquet(
    's3://overturemaps-us-west-2/release/REPLACE_WITH_REVIEWED_RELEASE/theme=places/*/*'
  )
  WHERE addresses[1].country = 'AU'
    AND confidence >= 0.60
) TO 'overture-au-venues.csv' (HEADER, DELIMITER ',');
```

Review the release path and a sample of the resulting rows before importing.
Do not replace `REPLACE_WITH_REVIEWED_RELEASE` automatically.

## Import gate

Run database updates first. Then validate without writes:

```text
ddev drush mel:venue-import-overture /path/overture-au-venues.csv --dry-run
```

After reviewing the accepted/skipped counts and sample source data:

```text
ddev drush mel:venue-import-overture /path/overture-au-venues.csv --yes
```

The import updates matching GERS IDs. It does not delete existing catalogue
rows, venue entities, organiser edits, or media.
