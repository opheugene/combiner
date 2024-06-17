# Combiner
Service for combining duplicate clients in Simla.com

```
bin/console duplicates:by email|phone criteria1 criteria2 criteria3 ...  --fields=field1,field2,field3,...
```

**Criteria:**

* **externalId**: the externalId value exists
* **ordersCount**: which customer has more orders
* **totalSumm**: which customer has more totalSumm
* **customFieldsCount**: which customer has more completed customFields
* **email**: the email value exists
* **phone**: at least one phone number exists
* **phoneExactLength**: the length of one of the client's phones is greater than or equal to a certain number of digits (use `--phoneExactLength` option to specify a number of digits)
* **sourcePriority**: client source priority (use `--sourcePriority` option to specify priority of sources: `--sourcePriority=Excel=10,PrestaShop=8,Messanger=4`)
* **createdAt**: an older client is more important
* **moreData**: client with more fulfilled card is more important ('firstName', 'lastName', 'email', 'phones', 'birthday', 'address')
* **hasChat**: the mgCustomers array exists. If all duplicates have mgCustomers array, customer with active channel is more important
* By default: which customer have that field
* CustomFields can be passed in format: ```customFields.field```: which customer have that field 

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

To merge numbers to number with country code add `--merge-phones`
```
bin/console duplicates:by phone externalId ordersCount email phone createdAt --csv --merge-phones
```

To collect all emails in resulting customer custom field add `--collectEmails`
```
bin/console duplicates:by phone externalId ordersCount email phone createdAt --csv --collectEmails
```

To merge other customer fields add `--mergeFields`
```
bin/console duplicates:by email externalId ordersCount email createdAt --csv --mergeFields=customField.cedula,birthday
```

To combine customers from specific sites add `--filter-sites`. If you need to combine customers without a site, use `_`
```
bin/console duplicates:by email ordersCount createdAt --csv --filter-sites='_,site1'
```
To combine customers from all sites add just `--all-sites`
```
bin/console duplicates:by email ordersCount createdAt --csv --all-sites
```

To check customer orders parameters add `--consider-orders`. Example for configFile.yaml:
```
arguments:
    criteria:
        - ordersCount
        - createdAt

options:
    crmUrl: 'crmUrl'
    apiKey: 'apiKey'
    fields: 'id,email,site'
    consider-orders:
        orderType:
            - 'mostImportantType'
            - 'type'
            - 'lessImportantType'
        createdAt: true


    all-sites: true
    no-cache: true
    csv: true
```

**Config file:**

Full config file:
```
arguments:
    criteria:
        - externalId
        - ordersCount
        - totalSumm
        - customFieldsCount
        - email
        - phone
        - phoneExactLength
        - sourcePriority
        - createdAt
        - moreData
        - hasChat

options:
    crmUrl: 'crmUrl'
    apiKey: 'apiKey'
    fields: 'id,email,site,all needed in table fields'
    consider-orders:
        orderType:
            - 'mostImportantType'
            - 'type'
            - 'lessImportantType'
        createdAt: true
    merge-phones: '10'
    collectEmails: 'secondary_email'
    mergeFields: 'birthday'
    
    phoneExactLength: '11'
    sourcePriority: 'Excel=10,PrestaShop=8,Messanger=4'
    exclude: 'unwanted to combine email or phone'

    merge-managers: true

    all-sites: false
    filter-sites: '_,site1'
    no-cache: true
    csv: true
    combine: false
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