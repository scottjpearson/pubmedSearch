var lookupAssistant = lookupAssistant || {};
var langMsg68 = '';


lookupAssistant.init = function() {

  setting = lookupAssistant.settings[0];

  lookupAssistant.log("Setting up field: " + setting.citations);

  // Make a pointer for each of the input fields
  $.each(lookupAssistant.settings[0], function(k, v){
    lookupAssistant[k] = $('[name="' + v + '"]');
    lookupAssistant[k].addClass('pubmed_search_field');
  });

  var r= $('<button id="publications_button" class="btn btn-defaultrc btn-sm" value="value">Get New From Pubmed</>');
  r.insertBefore(lookupAssistant.citations);

  $("<div id='loading' style='display:none'>LOADING YOUR CITATIONS FROM PUBMED. THIS MAY TAKE A MINUTE...</div>").insertBefore(lookupAssistant.citations);

  lookupAssistant.container  = $('<div class="lookupAssistant"></div>')
  .insertBefore(lookupAssistant.citations);

  lookupAssistant.sel = $('<select class="select-2-multi"/>')
  .wrapAll('<div/>')
  .appendTo(lookupAssistant.container)

  lookupAssistant.log("Set up done");
  lookupAssistant.log("Post-Init Settings:", lookupAssistant.settings);

  $('<hr/>').css({ 'margin':'3px 3px'}).appendTo(lookupAssistant.container);

  if (lookupAssistant.citations.val() !== '') {
    lookupAssistant.container.toggle();
  }

  // Hide the select until populated with options
  $('.select-2-multi').closest('div.lookupAssistant').hide();

  // Convert select2 inputs of type search to text so that the backspace key works
  $('body').on('focus', ".select2-search__field", function() {
    // lookupAssistant.log(this);
    if ( $(this).attr('type') === 'search') $(this).attr('type','text');
  });
};

lookupAssistant.mapJson = function(nested_json) {
  map = [];
  $.each(nested_json, function (i, datum){
    $.each(datum, function(j, data_part){
      parse = JSON.parse(data_part);
      if(typeof parse =='object'){
        map[j] = parse;
      }else{
        map[j] = data_part;
      }
    });
  });
  return map;
}

lookupAssistant.populateDropdown = function(citations) {
  $.each(citations, function(j, citation) {
    pmid = citation.split(":").slice(-1).pop().replace('.','');
    lookupAssistant.log("Setting up level " + j, pmid);
    citation.level = j;
    lookupAssistant.sel.attr('id', 'select-' + j)
    .data('level', j)
    .append(new Option(citation, pmid));
  });
}

lookupAssistant.getInstitutions = function(select) {
  institutions = [lookupAssistant.settings[0].institution];
  $.each(lookupAssistant.institution_fields, function(i, inst_field){
    institutions.push(inst_field.value);
  });
  return institutions;
}

lookupAssistant.updatePubFields = function(select) {
  lookupAssistant.log('setting Redcap Value for: ', select);
  if ($(select).text() == '') {
    // Don't do anything
  } else {
    // update citation strings
    existing = lookupAssistant.citations.text() || "";
    // addition = $(select).value().split(/(?<=PMID:\s\d*\.)/).join("\n")
    addition = $('.select-2-multi option:selected').toArray().map(item => item.text).join("\n");

    existing = existing ? existing+"\n" : "";
    var t = lookupAssistant.citations
    // need to parse so the format matches before filling...
    .val(existing+addition)
    .trigger('blur');

    // update helper
    existing = lookupAssistant.helper.val() ? JSON.parse(lookupAssistant.helper.val()) : [];
    existing.push($(select).val().toString().replace(/\s/g, "").split(','));
    var h = lookupAssistant.helper
    .val(JSON.stringify(existing.flat()))
    .trigger('blur');

    // update count
    existing = lookupAssistant.count.val() || 0;
    var n = lookupAssistant.count
    .val(parseInt(existing)+$(select).val().length)
    .trigger('blur');
  }
};

lookupAssistant.log = function() {
  if (this.jsDebug) console.log.apply(null, arguments);
};

$(document).ready(function(){
  // config ajax to hide/show items while loading
  $.ajaxSetup({
    beforeSend:function(){
      $("#loading").show();
    },
    complete:function(){
      $("#loading").hide();
    }
  });

  lookupAssistant.init();

  // Make target read-only
  if (lookupAssistant.settings[0]['edit-citations'] !== true) {
    lookupAssistant.citations.attr('disabled','disabled').closest('tr');
    lookupAssistant.helper.attr('disabled','disabled').closest('tr').addClass('@READONLY');
    lookupAssistant.count.attr('disabled','disabled').closest('tr').addClass('@READONLY');
  }

  // Make the select a select2
  $('.select-2-multi').select2({
    width: 'resolve',
    placeholder: 'Select your publications',
    closeOnSelect: false,
    theme: 'bootstrap',
    width: '90%',
    multiple: true,
    allowClear: true
  })
  // Select2 events
  .on('select2:unselecting', function() {
    $(this).data('unselecting', true);
  }).on('select2:opening', function(e) {
    if ($(this).data('unselecting')) {
      $(this).removeData('unselecting');
      e.preventDefault();
    }
  }).on('select2:open', function() {
    lookupAssistant.citations.hide();
  }).on('select2:select', function() {
  }).on('select2:closing', function() {
    lookupAssistant.updatePubFields(this);
    lookupAssistant.citations.show();
    $('.select-2-multi').closest('div.lookupAssistant').hide();
    if (lookupAssistant.settings[0]['edit-citations'] !== true) {
      lookupAssistant.citations.attr('disabled','disabled').closest('tr').addClass('@READONLY');
    }
  });

  // ajax post to get new pmids from pubmed for a single person
  $('#publications_button').on('click', function() {
    $.ajax({
      url: lookupAssistant.settings[0]['module-path'],
      'method':'POST',
      data: JSON.stringify({
        'record_id':lookupAssistant.settings[0]['record_id'],
        'first':lookupAssistant.first_name.val(),
        'last':lookupAssistant.last_name.val(),
        'institution':lookupAssistant.getInstitutions()
      }),
    })
    .done(function(data) {
      $("#loading").hide();
      $('#publications_button').hide();
      nested_json = JSON.parse(data);
      unnested_json = lookupAssistant.mapJson(nested_json);

      // save it for later
      lookupAssistant.unnestedCitations = unnested_json;

      // Add citations to the dropdown
      lookupAssistant.populateDropdown(unnested_json.citations);
      $('.select-2-multi').closest('div.lookupAssistant').show();
      $('.select-2-multi').select2('open');
    });
    return false; // keeps the page from not refreshing
  });
});
