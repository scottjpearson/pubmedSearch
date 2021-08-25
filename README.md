# pubmedSearch
This module takes last_name and first_name fields and downloads all PubMed entries for that name. It puts the PubMed entries into another REDCap field. It also requires one helper text field. It checks PubMed once a day for new entries. Because of inaccuracies of the process, it is recommended that human eyes manually oversee the download process.

This module goes through each REDCap record and searches for an individual (first-name/last-name pairing) on PubMed's API that is affiliated with an institution. A default institution can be specified for the entire list. Additional institutions (e.g., in prior appointments) can be specified via one-or-more fields in the record.

# Cron job

This module runs via a REDCap cron (background process). It runs approximately once per week.

The maximum run-time for this cron is 2 hours. Although it is unlikely that it will take this long, large (thousands of records) project could potentially trip this limit.

# Manual review

It is recommended that a user scan over the entries for correctness. Disambiguation (telling that two different names are different) can be difficult for common names, like Smith or Xu. The institutional affiliation helps this, but it is not perfect.

# REDCap Preparation

A field of type 'notes box' needs to be created for the citations to be placed into.

Also, a field of type 'text box' needs to be created to store the number of citations that are downloaded.

Finally, a field of type 'text box' needs to be created to store a record of which PubMed IDs have been downloaded so that duplicates aren't downloaded. (It is recommended that this final box use the field annotation of '@HIDDEN' so that it is hidden to users. It should not be manually modified; the script will do it all for you!)

# Lookup Assistant

This external module provides an easy way to find values from a large list
and add them to a REDCap input field.

It supports a single group of choices or a nested hierarchy, such as the:
Year, Make, Model or see the example with Country, State, City.

The 'hierarchy' file must be in JSON format and looks something like:
```json
{
  "United States": {
    "Minnesota": {
      "Minneapolis": "Minneapolis",
      "St. Paul": "St. Paul",
    },
    "California": {
      "Fresno": "Fresno"
    }
  }
}
```
Note that the last level should just have a value that matches the key.

Only the last level is stored in the input field so it doesn't work backwards and you should probably design your
hierarchy so that it supports unique names for the last level.

It might be useful to use this if you have a very large list of values to choose from.


## Options
When you skip a level in the hierarchy, there are two options.  You can either not permit selection of a 'state' until
a 'country' is selected.  Or, you can have all states merged into a single field when no country is selected.  This
second option can cause delays if the hierarchy is large as the processing is done on the client.

You can choose whether the final text entry field is editable or not.  If it is editable, one can choose a value
that is not part of the lookup.  So, you can try and reduce duplicate entries but still permit the entry of a value
not in your hierarchy.
