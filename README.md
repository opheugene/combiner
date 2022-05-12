# Combiner
Service for combining duplicate clients in Simla.com

```bin/console duplicates:by email|phone field1, field2, field3, ...```

**Connection settings:**

* copy `.env.dist` to `.env`
* specify `CRM_API_URL` and `CRM_API_KEY` in `.env`

**Example usage:**

Show report with duplicates by email with a table that consists of a list of fields: id, externalId, createdAt.date, firstName, lastName, source.source, ordersCount:
```bin/console duplicates:by email id externalId createdAt.date firstName lastName source.source ordersCount```

At the first launch, the command downloads all customers from CRM and use them to build reports or combine duplicates.

In order to use the latest data from CRM, add `--no-cache`:
```bin/console duplicates:by email id externalId createdAt.date firstName lastName source.source ordersCount --no-cache```

To save report to CSV file add `--csv`:
```bin/console duplicates:by email id externalId createdAt.date firstName lastName source.source ordersCount --csv```

To execute combining duplicates add `--combine`:
```bin/console duplicates:by email id externalId createdAt.date firstName lastName source.source ordersCount --combine```

You can combine options when calling the command, as well as adding option `--silent` to disable message output.

