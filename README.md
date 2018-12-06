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
