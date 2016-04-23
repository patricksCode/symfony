
/*
 * Report functions
 * 
 * 
 */

var Reporting = (function(){
	var reportListingLimit =10,
		report;
	
	return {
		
		// this function initializes all checkboxes on the search results page when the page loads and after performing infinte scrolling.
		initializeReportCheckboxes: function(){
			
			if($(PApp.report).size()){
				$.each(PApp.report, function(key, value){
					$('[pdf="' + value + '"]').data("state", true).animate({
			    		right: '-4'
			    	},100).addClass('active');
				});
			}

			// the next few lines binds a click function to all the pincushions on the search results page.
			// to view a pincushion, hover over a listing.
			// the state of the pincushion is stored in the pincushion using the jquery data method and this function checks that state when 
			// a pincushion is clicked.
			$('.pinCushion').unbind('click').click(function(e){
				e.preventDefault();
				if($(this).data('state') == true){
					$(this).data('state', false);
					$(this).removeClass('active');
					Reporting.removeFromReport($(this).attr('pdf'));				
				}else{
					if($('#reportCount').data('count') < reportListingLimit){
						$(this).data('state', true);
						$(this).addClass('active');
						Reporting.addToReport($(this).attr('pdf'));

					}else{
						Reporting.errorDialog('You have reach the limit of '+ reportListingLimit +' listings in a report.');
					}
				}
			});
			
		},
		
		// the next method displays a jquery ui dialog error box displaying the value of txt
		errorDialog: function(txt){
			$('#errorDialog .error-txt h3').html(txt);
			$('#errorDialog').dialog({
				title: 'Error',
				width: 400,
				resizable: false,
				modal: true,
				dialogClass: 'errorDialog',
		 		buttons: {  			 		
			 		"Cancel": function() { 
				 		$(this).dialog("close"); 				 		
			 		}
			 	}	 			
		 	});
		},
		
		
		// this next funchion intializes all the pincushions that are in the report
		updateReportCheckboxes: function(){

			var active = new Array();
			var i = 0;
			
			$(report).each(function(key, value){
				active[i] = String(value.type+"-"+value.id);
				i++;
			});

			$('.pinCushion').each(function(key, value){
				if($.inArray($(value).attr('pdf'), active)!=-1){
					$(this).data('state', true);
					$('[pdf="' + $(value).attr('pdf') + '"]').data("state", true).animate({
			    		right: '-4'
			    	},100).addClass('active');
				}else{
					$(this).data('state', false);
					Reporting.clearPinCushion($(value));
				}
				
			});
			
		},
		
		// this next function initialzes the hover hover state for the listings when the page loads and after infinite scrolling.
		// I unbind any events that are attached to the listings just in case.  i dont want to fire the same event multiple times.
		initializeListingHover: function(obj){		
			$(obj).unbind('hover').hover(
				 function() {
					$(this).find(".addressBackground").css('background-color', "#9E002D");
					 if($(this).find(".pinCushion").data('state')===false || $(this).find(".pinCushion").data('state')===undefined ){
			    	   	$(this).find(".pinCushion").animate({
				    		right: '-4'
				    	},100);
				    }
				},
				 function(){
					$(this).find(".addressBackground").css('background-color', "#000000");
						if($(this).find(".pinCushion").data('state')===false || $(this).find(".pinCushion").data('state')===undefined ){
						  $(this).find(".pinCushion").animate({
				    			right: '29'
				    		},100);
					  }
				}
			);
		},
	
		// this function adds a listing to the report using a jquery ajax post.
		addToReport: function(val){

			$.post(PApp.addToReportUrl+ "?" + Reporting.getTimeStamp(), { object: val },
			   function(data){
			    report = data;
			    Reporting.updateReportCount($(data).size());
			    Reporting.updateReportList();
			    if( $('#reportBox').is(':hidden') ) {
					Reporting.getReportSummary();
					$('#reportBox').slideDown("fast", "swing", function(){ 
						$('#reportBox:not(:hidden)').delay(2000).slideUp("fast", "swing");
					});
				}
			   }, "json");

	
		},
		
		// this function removes a listing from the report
		removeFromReport: function(val){
			$.post(PApp.removeFromReportUrl+ "?" + Reporting.getTimeStamp(), { object: val },
			   function(data){		  
			    report = data;
			    Reporting.updateReportCount($(data).size());
			    Reporting.updateReportList();
			   }, "json");
	
		},
		
		// this function updateds the report list that is displayed when you click on the report icon.
		updateReportList: function(){
			$('#reportList').empty();
			if($(report).size() < 1){	
				$('#clearReport').hide();
				$('#printReport').hide();
				$('#reportList').append('<li>No items in report.</li>');
				if($('#removeFromReport').size()){
					Reporting.turnOnAddToReportButton($('#removeFromReport'));
				}
			}else{
				$(report).each(function(key, val){
					newLineItem = $('<li/>');
					var objectId=val.type + "-" + val.id;
					deleteListing = $('<a href="" class="del-listing"/>').click(function(e){
							e.preventDefault();						
							Reporting.removeFromReport(objectId);						
							Reporting.clearPinCushion($('[pdf="'+objectId+'"]'));
							Reporting.initializeListingHover($('[pdf="'+objectId+'"]').parent());
							Reporting.turnOnAddToReportButton($('[value="'+objectId+'"]'));
						});
					addressLink = $('<a href="'+ val.link +'" target="_blank" />').html(val.address);
		
					newLineItem.append(addressLink).append(deleteListing)
					$('#reportList').append(newLineItem);
					Reporting.turnOnRemoveFromReportButton($('[value="'+objectId+'"]'));
					$('#clearReport').show();
					$('#printReport').show();
				});
			}
			
		},
		
		// this function gets a report summary from php using ajax.  this is performed when you click on the report icon.
		getReportSummary: function(){
			$.post(PApp.reportSummaryUrl + "?" + Reporting.getTimeStamp(),
					   function(data){	
					    report = data;
					    Reporting.updateReportList();
					    Reporting.updateReportCount($(data).size());
					    Reporting.updateReportCheckboxes();
					   }, "json");
			
		},
		
		// initializes the add listing to report based on how many listings are in the report.  the max is 10.
		initializeAddToReport: function(){
			$('#addToReport').click(function(e){
				e.preventDefault();
				if($('#reportCount').data('count') < reportListingLimit){
					Reporting.addToReport($(e.currentTarget).attr('value'));
					Reporting.turnOnRemoveFromReportButton($(e.currentTarget));
					
				}else{
					alert('You have reach the limit of '+ reportListingLimit +' listings in a report.');
				}
			});
		},
		
		// this changes an add to report icon into a remove from report icon
		turnOnRemoveFromReportButton: function(el){
			$(el).unbind().attr({id: 'removeFromReport', title: 'Remove from report'}).removeClass('btn-report-addListing').addClass('btn-report-removeListing');
			Reporting.initializeRemoveFromReport();
		},
		
		// this changes a remove from report icon into an add to report icon
		turnOnAddToReportButton: function(el){
			$(el).unbind().attr({id: 'addToReport', title: 'Add to report'}).removeClass('btn-report-removeListing').addClass('btn-report-addListing');
			Reporting.initializeAddToReport();
		},
		
		// initializes the remove listing from report 
		initializeRemoveFromReport: function(){
			$('#removeFromReport').click(function(e){
				e.preventDefault();
				Reporting.removeFromReport($(e.currentTarget).attr('value'));
				Reporting.turnOnAddToReportButton($(e.currentTarget));
				
			});
		},
		
	
		// this function clears all listings from the report using ajax and helper methods
		clearReport: function(){
			$.post(PApp.clearReportUrl+ "?" + Reporting.getTimeStamp(),
					   function(data){		  
						    report = data;
						    $('.pinCushion').each(function(key, val){
						    	 Reporting.clearPinCushion($(this));
						    });
						    Reporting.updateReportList();
						    Reporting.updateReportCount($(data).size());
						    Reporting.turnOnAddToReportButton($('#removeFromReport'));
					   }, "json");
			
		},
		
		// this function sets all pincushions to a false state.
		clearPinCushion: function(pinCushion){
							$(pinCushion).animate({
					    		right: '29'
					    	},100)
					    	.removeClass('active');
						    $(pinCushion).data('state', false);
						    
		}, 
		
		
		// this function updates the report count on the listing and search results page.
		updateReportCount: function(val){
			if(val =="+"){
				var newVal = $('#reportCount').data('count')++;

			}else if(val =="-"){
				var newVal = $('#reportCount').data('count')--;
				if(newVal<0) newVal=0;
				
			}else if(parseInt(val, 10)!=NaN){
				newVal = parseInt(val, 10);
			}
			$('#reportCount').data('count',newVal);
			document.getElementById('reportCount').innerHTML = newVal;
		},
		
		getTimeStamp: function(){
			var dateObject = new Date; 
			return dateObject.getTime();
		}	
	};		
})();


// all the items below initialize the search results page and the listing page.
$(document).ready(function () {	
	Reporting.initializeAddToReport();		
	Reporting.initializeRemoveFromReport();
	
	$('#viewReport,  #reportWindowClose').click(function(e){
		e.preventDefault();
		if( $('#reportBox').is(':hidden') ) {
			Reporting.getReportSummary();
			$('.dropBox').hide();
			//if($('#reportCount').data('count')){
				$('#reportBox').slideDown("fast", "swing");
			//}
		}else{
			$('#reportBox').slideUp("fast", "swing");
		}	
		
	});

	Reporting.initializeReportCheckboxes();

	$('#clearReport').click(function(e){
		e.preventDefault();
		Reporting.clearReport();
	});
	Reporting.updateReportCount(PApp.reportCount);


});
