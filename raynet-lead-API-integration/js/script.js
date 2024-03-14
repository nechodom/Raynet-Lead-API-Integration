jQuery(document).ready(function ($) {
  $("#raynetLeadForm").submit(function (e) {
    e.preventDefault();

    var formData = {
      topic: $("#topic").val(),
      priority: "DEFAULT",
      notice: raynetCredentials.note,
      contactInfo: {
        email: $("#email").val(),
      },
      firstName: $("#firstName").val(),
      lastName: $("#lastName").val(),
    };

    console.log("Form Data: ", formData);

    $.ajax({
      type: "PUT",
      url: raynetCredentials.apiUrl, // URL on API
      contentType: "application/json",
      dataType: "json",
      headers: {
        Authorization:
          "Basic " +
          btoa(raynetCredentials.username + ":" + raynetCredentials.apiKey),
        "X-Instance-Name": raynetCredentials.instanceName,
      },
      data: JSON.stringify(formData),
      success: function (response) {
        console.log("Success:", response);
        $("#formNotification").html(
          '<p style="color: green;">Formulář byl úspěšně odeslán.</p>'
        );
      },
      error: function (xhr, status, error) {
        console.error("Error:", xhr.responseText);
        $("#formNotification").html(
          '<p style="color: red;">Odeslání formuláře selhalo. Zkuste to prosím znovu.</p>'
        );
      },
    });
  });
});
