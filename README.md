# Combiner
Service for combining duplicate clients in Simla.com

```
bin/console duplicates:by email|phone criteria1 criteria2 criteria3 ...  --fields=field1,field2,field3,...
```

**Criteria:**

* **externalId**: the externalId value exists
* **ordersCount**: which customer has more orders
* **email**: the email value exists
* **phone**: at least one phone number exists
* **phoneExactLength**: the length of one of the client's phones is greater than or equal to a certain number of digits (use `--phoneExactLength` option to specify a number of digits)
* **sourcePriority**: client source priority (use `--sourcePriority` option to specify priority of sources: `--sourcePriority=Excel=10,PrestaShop=8,Messanger=4`)
* **createdAt**: an older client is more important
* **moreData**: client with more fulfilled card is more important ('firstName', 'lastName', 'email', 'phones', 'birthday', 'address')

**Connection settings:**

* copy `.env.dist` to `.env`
* specify `CRM_API_URL` and `CRM_API_KEY` in `.env`

**Example usage:**

Show report with duplicates by email, compare clients by criteria: externalId, ordersCount, email, phone, createdAt and with a table that consists of a list of fields: id, externalId, createdAt.date, firstName, lastName, source.source, ordersCount:
```
bin/console duplicates:by email externalId ordersCount email phone createdAt --fields=id,externalId,createdAt.date,firstName,lastName,email,phones,source.source,ordersCount
```

At the first launch, the command downloads all customers from CRM and use them to build reports or combine duplicates.

In order to use the latest data from CRM, add `--no-cache`:
```
bin/console duplicates:by email externalId ordersCount email phone createdAt --fields=id,externalId,createdAt.date,firstName,lastName,email,phones,source.source,ordersCount --no-cache
```

To save report to CSV file add `--csv`:
```
bin/console duplicates:by email externalId ordersCount email phone createdAt --fields=id,externalId,createdAt.date,firstName,lastName,email,phones,source.source,ordersCount --csv
```

To execute combining duplicates add `--combine`:
```
bin/console duplicates:by email externalId ordersCount email phone createdAt --fields=id,externalId,createdAt.date,firstName,lastName,email,phones,source.source,ordersCount --combine
```

To merge managers add `--merge-managers`
```
bin/console duplicates:by phone externalId ordersCount email phone createdAt --csv --merge-managers
```

To merge other customer fields add `--mergeFields`
```
bin/console duplicates:by email externalId ordersCount email createdAt --csv --mergeFields=customField.cedula,birthday
```

To periodically execute the command on CRON:
```
bin/console duplicates:by phone externalId ordersCount email phone createdAt --combine --no-cache
```

**Command arguments and options:**

To call command with arguments and options from config file, use `-c` or `--config` with path to config.yaml file
```
bin/console duplicates:by phone -c ./configFile.yaml
```