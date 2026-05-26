object FrmAutoInvoice: TFrmAutoInvoice
  Left = 0
  Top = 0
  Caption = 'Automatic Invoicing'
  ClientHeight = 383
  ClientWidth = 573
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Visible = True
  PixelsPerInch = 96
  TextHeight = 13
  object PanelGriniaris: TJvPanel
    Left = 0
    Top = 0
    Width = 573
    Height = 145
    Transparent = True
    Align = alTop
    TabOrder = 0
    DesignSize = (
      573
      145)
    object LedKeepAlive: TJvLED
      Left = 0
      Top = 0
    end
    object checkGriniaris: TJvCheckBox
      Left = 48
      Top = 5
      Width = 59
      Height = 17
      Caption = 'Griniaris'
      Checked = True
      State = cbChecked
      TabOrder = 0
      LinkedControls = <>
    end
    object cxLabel1: TcxLabel
      Left = 24
      Top = 89
      Caption = 'Invoices left'
      ParentFont = False
      Style.Font.Charset = DEFAULT_CHARSET
      Style.Font.Color = clWindowText
      Style.Font.Height = -15
      Style.Font.Name = 'Tahoma'
      Style.Font.Style = []
      Style.IsFontAssigned = True
      Properties.Alignment.Horz = taRightJustify
      AnchorX = 107
    end
    object LblInvoicesLeft: TcxLabel
      Left = 113
      Top = 89
      AutoSize = False
      Caption = '0'
      ParentFont = False
      Style.Font.Charset = DEFAULT_CHARSET
      Style.Font.Color = clWindowText
      Style.Font.Height = -15
      Style.Font.Name = 'Tahoma'
      Style.Font.Style = []
      Style.IsFontAssigned = True
      Height = 22
      Width = 99
    end
    object cxLabel2: TcxLabel
      Left = 62
      Top = 117
      Caption = 'Status'
      ParentFont = False
      Style.Font.Charset = DEFAULT_CHARSET
      Style.Font.Color = clWindowText
      Style.Font.Height = -15
      Style.Font.Name = 'Tahoma'
      Style.Font.Style = []
      Style.IsFontAssigned = True
      Properties.Alignment.Horz = taRightJustify
      AnchorX = 107
    end
    object LblStatus: TcxLabel
      Left = 113
      Top = 117
      Anchors = [akLeft, akTop, akRight]
      AutoSize = False
      Caption = '-'
      ParentFont = False
      Style.Font.Charset = DEFAULT_CHARSET
      Style.Font.Color = clWindowText
      Style.Font.Height = -15
      Style.Font.Name = 'Tahoma'
      Style.Font.Style = []
      Style.IsFontAssigned = True
      Height = 22
      Width = 420
    end
    object cxButton1: TcxButton
      Left = 479
      Top = 1
      Width = 75
      Height = 25
      Anchors = [akTop, akRight]
      Caption = 'Invoice Next'
      TabOrder = 5
      Visible = False
    end
    object checkThird: TJvCheckBox
      Left = 48
      Top = 22
      Width = 75
      Height = 17
      Caption = '3rd Invoice'
      Checked = True
      State = cbChecked
      TabOrder = 6
      LinkedControls = <>
    end
    object checkAssigned: TJvCheckBox
      Left = 48
      Top = 39
      Width = 64
      Height = 17
      Caption = 'Assigned'
      Checked = True
      State = cbChecked
      TabOrder = 7
      LinkedControls = <>
    end
    object checkMydata: TJvCheckBox
      Left = 48
      Top = 73
      Width = 62
      Height = 17
      Caption = 'MyDATA'
      Checked = True
      State = cbChecked
      TabOrder = 8
      LinkedControls = <>
      ReadOnly = True
    end
  end
  object MemoLog: TcxMemo
    Left = 0
    Top = 145
    Align = alClient
    Properties.ScrollBars = ssBoth
    TabOrder = 1
    Height = 238
    Width = 573
  end
  object checkMail: TJvCheckBox
    Left = 48
    Top = 56
    Width = 39
    Height = 17
    Caption = 'Mail'
    Checked = True
    State = cbChecked
    TabOrder = 2
    LinkedControls = <>
  end
  object QueryInvoices: TUniQuery
    Connection = sqlConnection
    SQL.Strings = (
      'select mi.*,'
      '      b.companyname,'
      '      b.address1,'
      '      b.address2,'
      '      b.city,'
      '      b.state,'
      '      b.postcode,'
      '      b.country,'
      '      b.phonenumber,'
      '      b.email,'
      '      b.firstname,'
      '      b.lastname,'
      '      (select value from tblcustomfieldsvalues'
      
        '          where relid = b.id and fieldid = 12 limit 1) as toinvo' +
        'ice,'
      '        (select value from tblcustomfieldsvalues'
      
        '          where relid = b.id and fieldid = 15 limit 1) as occupa' +
        'tion,'
      '        (select value from tblcustomfieldsvalues'
      '          where relid = b.id and fieldid = 13 limit 1) as vatno,'
      '       (select value  from tblcustomfieldsvalues'
      
        '          where relid = b.id and fieldid = 14 limit 1) as taxoff' +
        'ice,'
      '        G.gkriniaris,'
      '        TH.HAS_3RD_INVOICE'
      
        '        from tblinvoices mi left outer join tblclients b on mi.u' +
        'serid = b.id '
      
        '         left outer join (select relid, value as gkriniaris from' +
        ' tblcustomfieldsvalues where fieldid = 338) G on relid = b.id'
      
        '         left outer join (select DISTINCT invoiceid, 1 AS HAS_3R' +
        'D_INVOICE from tblinvoiceitems i LEFT OUTER join mod_timologia_s' +
        'ervicetypes s   on i.type = s.invline_servicetype'
      
        '                          '#9#9'left outer join mod_timologia t on i' +
        '.relid = t.serviceid and s.contact_servicetype = t.service_type'
      
        '                         '#9#9'where t.contactid is not null) TH ON ' +
        'mi.id = TH.invoiceid'
      '      where mi.status ='#39'Paid'#39' '
      'and mi.invoiced = 0 '
      'and gkriniaris = '#39'on'#39
      '    order by mi.datepaid asc')
    Left = 312
    Top = 24
    object QueryInvoicesid: TLongWordField
      FieldName = 'id'
    end
    object QueryInvoicesuserid: TIntegerField
      FieldName = 'userid'
      Required = True
    end
    object QueryInvoicesinvoicenum: TWideMemoField
      FieldName = 'invoicenum'
      Required = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesdate: TDateField
      FieldName = 'date'
    end
    object QueryInvoicesduedate: TDateField
      FieldName = 'duedate'
    end
    object QueryInvoicesdatepaid: TDateTimeField
      FieldName = 'datepaid'
      Required = True
    end
    object QueryInvoiceslast_capture_attempt: TDateTimeField
      FieldName = 'last_capture_attempt'
    end
    object QueryInvoicesdate_refunded: TDateTimeField
      FieldName = 'date_refunded'
    end
    object QueryInvoicesdate_cancelled: TDateTimeField
      FieldName = 'date_cancelled'
    end
    object QueryInvoicessubtotal: TFloatField
      FieldName = 'subtotal'
      Required = True
    end
    object QueryInvoicescredit: TFloatField
      FieldName = 'credit'
      Required = True
    end
    object QueryInvoicestax: TFloatField
      FieldName = 'tax'
      Required = True
    end
    object QueryInvoicestax2: TFloatField
      FieldName = 'tax2'
      Required = True
    end
    object QueryInvoicestotal: TFloatField
      FieldName = 'total'
      Required = True
    end
    object QueryInvoicestaxrate: TFloatField
      FieldName = 'taxrate'
      Required = True
    end
    object QueryInvoicestaxrate2: TFloatField
      FieldName = 'taxrate2'
      Required = True
    end
    object QueryInvoicesstatus: TWideMemoField
      FieldName = 'status'
      Required = True
      BlobType = ftWideMemo
    end
    object QueryInvoicespaymentmethod: TWideMemoField
      FieldName = 'paymentmethod'
      Required = True
      BlobType = ftWideMemo
    end
    object QueryInvoicespaymethodid: TLongWordField
      FieldName = 'paymethodid'
    end
    object QueryInvoicesnotes: TWideMemoField
      FieldName = 'notes'
      Required = True
      BlobType = ftWideMemo
    end
    object QueryInvoicescreated_at: TDateTimeField
      FieldName = 'created_at'
    end
    object QueryInvoicesupdated_at: TDateTimeField
      FieldName = 'updated_at'
    end
    object QueryInvoicesinvoiced: TSmallintField
      FieldName = 'invoiced'
    end
    object QueryInvoicescompanyname: TWideMemoField
      FieldName = 'companyname'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesaddress1: TWideMemoField
      FieldName = 'address1'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesaddress2: TWideMemoField
      FieldName = 'address2'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicescity: TWideMemoField
      FieldName = 'city'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesstate: TWideMemoField
      FieldName = 'state'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicespostcode: TWideMemoField
      FieldName = 'postcode'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicescountry: TWideMemoField
      FieldName = 'country'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesphonenumber: TWideMemoField
      FieldName = 'phonenumber'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesemail: TWideMemoField
      FieldName = 'email'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesfirstname: TWideMemoField
      FieldName = 'firstname'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoiceslastname: TWideMemoField
      FieldName = 'lastname'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicestoinvoice: TWideMemoField
      FieldName = 'toinvoice'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesoccupation: TWideMemoField
      FieldName = 'occupation'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesvatno: TWideMemoField
      FieldName = 'vatno'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicestaxoffice: TWideMemoField
      FieldName = 'taxoffice'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesgkriniaris: TWideMemoField
      FieldName = 'gkriniaris'
      ReadOnly = True
      BlobType = ftWideMemo
    end
    object QueryInvoicesHAS_3RD_INVOICE: TSmallintField
      FieldName = 'HAS_3RD_INVOICE'
    end
  end
  object TimerInvoicer: TJvTimer
    Interval = 2000
    OnTimer = TimerInvoicerTimer
    Left = 144
  end
  object sqlConnection: TUniConnection
    ProviderName = 'MySQL'
    Database = 'myipgr_clients'
    SpecificOptions.Strings = (
      'MySQL.Charset=UTF-8'
      'MySQL.UseUnicode=True')
    Username = 'myipgr_ekdosi'
    Server = 'sky.myip.gr'
    Connected = True
    LoginPrompt = False
    Left = 40
    Top = 144
    EncryptedPassword = '9DFF99FFC8FFB1FFB1FFABFFAAFFA9FF8FFFC8FF8AFFACFF'
  end
  object QueryInvLines: TUniQuery
    Connection = sqlConnection
    SQL.Strings = (
      'SELECT I.*,'
      
        '     (SELECT C.company_name FROM `mod_timologia` AS T LEFT OUTER' +
        ' JOIN `mod_timologia_contacts` AS C '
      #9#9#9'ON T.contactid = C.id'
      
        '         WHERE serviceid = I.relid and service_type = I.type) AS' +
        ' alt_company_name'
      'FROM `tblinvoiceitems` AS I'
      'WHERE invoiceid = :id')
    MasterSource = DSInvoices
    Left = 312
    Top = 80
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'id'
        Value = nil
      end>
    object QueryInvLinesid: TIntegerField
      AutoGenerateValue = arAutoInc
      FieldName = 'id'
    end
    object QueryInvLinesinvoiceid: TLongWordField
      FieldName = 'invoiceid'
      Required = True
    end
    object QueryInvLinesuserid: TIntegerField
      FieldName = 'userid'
      Required = True
    end
    object QueryInvLinestype: TWideStringField
      FieldName = 'type'
      Required = True
      Size = 30
    end
    object QueryInvLinesrelid: TIntegerField
      FieldName = 'relid'
      Required = True
    end
    object QueryInvLinesdescription: TWideMemoField
      FieldName = 'description'
      Required = True
      BlobType = ftWideMemo
    end
    object QueryInvLinesamount: TFloatField
      FieldName = 'amount'
      Required = True
    end
    object QueryInvLinestaxed: TIntegerField
      FieldName = 'taxed'
      Required = True
    end
    object QueryInvLinesduedate: TDateField
      FieldName = 'duedate'
    end
    object QueryInvLinespaymentmethod: TWideMemoField
      FieldName = 'paymentmethod'
      Required = True
      BlobType = ftWideMemo
    end
    object QueryInvLinesnotes: TWideMemoField
      FieldName = 'notes'
      Required = True
      BlobType = ftWideMemo
    end
    object QueryInvLinesalt_company_name: TWideStringField
      FieldName = 'alt_company_name'
      ReadOnly = True
      Size = 128
    end
  end
  object DSInvoices: TDataSource
    AutoEdit = False
    DataSet = QueryInvoices
    Left = 360
    Top = 24
  end
  object QueryLocalCustomer: TIBQuery
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    ParamCheck = True
    SQL.Strings = (
      'SELECT CUSTOMER.* FROM CUSTOMER'
      'WHERE AFM = :AFM')
    Left = 512
    Top = 48
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'AFM'
        ParamType = ptUnknown
      end>
    object QueryLocalCustomerCUST_ID: TIntegerField
      FieldName = 'CUST_ID'
      Origin = '"CUSTOMER"."CUST_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
    object QueryLocalCustomerALT_CUSTID: TIntegerField
      FieldName = 'ALT_CUSTID'
      Origin = '"CUSTOMER"."ALT_CUSTID"'
    end
    object QueryLocalCustomerAFM: TIBStringField
      FieldName = 'AFM'
      Origin = '"CUSTOMER"."AFM"'
    end
    object QueryLocalCustomerNAME: TIBStringField
      FieldName = 'NAME'
      Origin = '"CUSTOMER"."NAME"'
      Required = True
      Size = 50
    end
    object QueryLocalCustomerADDRESS1: TIBStringField
      FieldName = 'ADDRESS1'
      Origin = '"CUSTOMER"."ADDRESS1"'
      Size = 50
    end
    object QueryLocalCustomerADDRESS2: TIBStringField
      FieldName = 'ADDRESS2'
      Origin = '"CUSTOMER"."ADDRESS2"'
      Size = 50
    end
    object QueryLocalCustomerCITY: TIBStringField
      FieldName = 'CITY'
      Origin = '"CUSTOMER"."CITY"'
      Size = 30
    end
    object QueryLocalCustomerPOSTCODE: TIBStringField
      FieldName = 'POSTCODE'
      Origin = '"CUSTOMER"."POSTCODE"'
      Size = 10
    end
    object QueryLocalCustomerPHONE1: TIBStringField
      FieldName = 'PHONE1'
      Origin = '"CUSTOMER"."PHONE1"'
      Size = 30
    end
    object QueryLocalCustomerPHONE2: TIBStringField
      FieldName = 'PHONE2'
      Origin = '"CUSTOMER"."PHONE2"'
      Size = 30
    end
    object QueryLocalCustomerFAX: TIBStringField
      FieldName = 'FAX'
      Origin = '"CUSTOMER"."FAX"'
      Size = 30
    end
    object QueryLocalCustomerOCCUPATION: TIBStringField
      FieldName = 'OCCUPATION'
      Origin = '"CUSTOMER"."OCCUPATION"'
      Size = 120
    end
    object QueryLocalCustomerTAXOFFICE: TIBStringField
      FieldName = 'TAXOFFICE'
      Origin = '"CUSTOMER"."TAXOFFICE"'
      Size = 30
    end
    object QueryLocalCustomerDETAILS: TWideMemoField
      FieldName = 'DETAILS'
      Origin = '"CUSTOMER"."DETAILS"'
      ProviderFlags = [pfInUpdate]
      BlobType = ftWideMemo
      Size = 8
    end
    object QueryLocalCustomerDISCOUNT: TIBBCDField
      FieldName = 'DISCOUNT'
      Origin = '"CUSTOMER"."DISCOUNT"'
      Precision = 18
      Size = 2
    end
    object QueryLocalCustomerSECONDARY_EMAIL: TIBStringField
      FieldName = 'SECONDARY_EMAIL'
      Origin = '"CUSTOMER"."SECONDARY_EMAIL"'
      Size = 120
    end
    object QueryLocalCustomerEMAIL: TIBStringField
      FieldName = 'EMAIL'
      Origin = '"CUSTOMER"."EMAIL"'
      Size = 120
    end
    object QueryLocalCustomerORDER: TIntegerField
      FieldName = 'ORDER'
      Origin = '"CUSTOMER"."ORDER"'
    end
    object QueryLocalCustomerCOUNTRY: TIBStringField
      FieldName = 'COUNTRY'
      Origin = '"CUSTOMER"."COUNTRY"'
      Size = 40
    end
    object QueryLocalCustomerPAYMETH_ID: TIntegerField
      FieldName = 'PAYMETH_ID'
      Origin = '"CUSTOMER"."PAYMETH_ID"'
    end
    object QueryLocalCustomerVAT_VIES: TIBStringField
      FieldName = 'VAT_VIES'
      Origin = '"CUSTOMER"."VAT_VIES"'
      Size = 30
    end
  end
  object QueryThirdInvoice: TUniQuery
    Connection = sqlConnection
    SQL.Strings = (
      'select  t.contactid,'
      '      c.id,'
      '      c.userid,'
      '      c.company_name as companyname,'
      '      c.address1,'
      '      c.address2,'
      '      c.postal_code as postcode,'
      '      c.city,'
      '      c.telephone as phonenumber,'
      '      c.country,'
      '      c.email,'
      '      c.tax_office as taxoffice,'
      '      c.description as occupation,'
      '      c.gr_vatno as vatno,'
      '      c.company_name as lastname, '#39#39' as firstname, '
      '      t.isReceipt,'
      '      count(*)'
      
        'from `tblinvoiceitems` i left outer join  mod_timologia_servicet' +
        'ypes s on i.type = s.invline_servicetype'
      
        ' right outer join (`mod_timologia` t left outer join `mod_timolo' +
        'gia_contacts` as c on t.contactid = c.id)'
      
        #9#9#9#9#9'on i.userid = t.userid and i.relid = t.serviceid and t.serv' +
        'ice_type = s.contact_servicetype'
      '                    '
      ' where i.invoiceid = :invoiceid'
      ' group by t.contactid'
      ' order by count(*) desc')
    Left = 312
    Top = 200
    ParamData = <
      item
        DataType = ftUnknown
        Name = 'invoiceid'
        Value = nil
      end>
    object QueryThirdInvoicecontactid: TIntegerField
      FieldName = 'contactid'
      Required = True
    end
    object QueryThirdInvoiceid: TIntegerField
      AutoGenerateValue = arAutoInc
      FieldName = 'id'
      ReadOnly = True
    end
    object QueryThirdInvoiceuserid: TIntegerField
      FieldName = 'userid'
      ReadOnly = True
    end
    object QueryThirdInvoicecompanyname: TWideStringField
      FieldName = 'companyname'
      ReadOnly = True
      Size = 128
    end
    object QueryThirdInvoiceaddress1: TWideStringField
      FieldName = 'address1'
      ReadOnly = True
      Size = 60
    end
    object QueryThirdInvoiceaddress2: TWideStringField
      FieldName = 'address2'
      ReadOnly = True
      Size = 60
    end
    object QueryThirdInvoicepostcode: TWideStringField
      FieldName = 'postcode'
      ReadOnly = True
      Size = 10
    end
    object QueryThirdInvoicecity: TWideStringField
      FieldName = 'city'
      ReadOnly = True
      Size = 60
    end
    object QueryThirdInvoicephonenumber: TWideStringField
      FieldName = 'phonenumber'
      ReadOnly = True
    end
    object QueryThirdInvoicecountry: TWideStringField
      FieldName = 'country'
      ReadOnly = True
      Size = 60
    end
    object QueryThirdInvoiceemail: TWideStringField
      FieldName = 'email'
      ReadOnly = True
      Size = 60
    end
    object QueryThirdInvoicetaxoffice: TWideStringField
      FieldName = 'taxoffice'
      ReadOnly = True
      Size = 60
    end
    object QueryThirdInvoiceoccupation: TWideStringField
      FieldName = 'occupation'
      ReadOnly = True
      Size = 120
    end
    object QueryThirdInvoicevatno: TWideStringField
      FieldName = 'vatno'
      ReadOnly = True
      Size = 30
    end
    object QueryThirdInvoicelastname: TWideStringField
      FieldName = 'lastname'
      ReadOnly = True
      Size = 128
    end
    object QueryThirdInvoicefirstname: TWideStringField
      FieldName = 'firstname'
      ReadOnly = True
      Required = True
      Size = 65530
    end
    object QueryThirdInvoicecount: TLargeintField
      FieldName = 'count(*)'
      ReadOnly = True
      Required = True
    end
    object QueryThirdInvoiceisReceipt: TBooleanField
      FieldName = 'isReceipt'
    end
  end
  object DatasetCustomer: TIBDataSet
    Database = FrmMain.database
    Transaction = FrmMain.IBTransaction1
    BufferChunks = 1000
    CachedUpdates = False
    DeleteSQL.Strings = (
      'delete from "CUSTOMER"'
      'where'
      '  "CUSTOMER"."CUST_ID" = :"OLD_CUST_ID"')
    InsertSQL.Strings = (
      'insert into "CUSTOMER"'
      
        '  ("CUSTOMER"."CUST_ID", "CUSTOMER"."ALT_CUSTID", "CUSTOMER"."AF' +
        'M", "CUSTOMER"."NAME", '
      
        '   "CUSTOMER"."ADDRESS1", "CUSTOMER"."ADDRESS2", "CUSTOMER"."CIT' +
        'Y", "CUSTOMER"."POSTCODE", '
      
        '   "CUSTOMER"."PHONE1", "CUSTOMER"."PHONE2", "CUSTOMER"."FAX", "' +
        'CUSTOMER"."OCCUPATION", '
      
        '   "CUSTOMER"."TAXOFFICE", "CUSTOMER"."DETAILS", "CUSTOMER"."DIS' +
        'COUNT", '
      
        '   "CUSTOMER"."SECONDARY_EMAIL", "CUSTOMER"."EMAIL", "CUSTOMER".' +
        '"ORDER", '
      
        '   "CUSTOMER"."COUNTRY", "CUSTOMER"."PAYMETH_ID", "CUSTOMER"."VA' +
        'T_VIES", '
      '   "CUSTOMER"."TYPE")'
      'values'
      
        '  (:"CUST_ID", :"ALT_CUSTID", :"AFM", :"NAME", :"ADDRESS1", :"AD' +
        'DRESS2", '
      
        '   :"CITY", :"POSTCODE", :"PHONE1", :"PHONE2", :"FAX", :"OCCUPAT' +
        'ION", :"TAXOFFICE", '
      
        '   :"DETAILS", :"DISCOUNT", :"SECONDARY_EMAIL", :"EMAIL", :"ORDE' +
        'R", :"COUNTRY", '
      '   :"PAYMETH_ID", :"VAT_VIES", :"TYPE")')
    RefreshSQL.Strings = (
      'Select '
      '  "CUSTOMER"."CUST_ID",'
      '  "CUSTOMER"."ALT_CUSTID",'
      '  "CUSTOMER"."AFM",'
      '  "CUSTOMER"."NAME",'
      '  "CUSTOMER"."ADDRESS1",'
      '  "CUSTOMER"."ADDRESS2",'
      '  "CUSTOMER"."CITY",'
      '  "CUSTOMER"."POSTCODE",'
      '  "CUSTOMER"."PHONE1",'
      '  "CUSTOMER"."PHONE2",'
      '  "CUSTOMER"."FAX",'
      '  "CUSTOMER"."OCCUPATION",'
      '  "CUSTOMER"."TAXOFFICE",'
      '  "CUSTOMER"."DETAILS",'
      '  "CUSTOMER"."DISCOUNT",'
      '  "CUSTOMER"."SECONDARY_EMAIL",'
      '  "CUSTOMER"."EMAIL",'
      '  "CUSTOMER"."ORDER",'
      '  "CUSTOMER"."COUNTRY",'
      '  "CUSTOMER"."PAYMETH_ID",'
      '  "CUSTOMER"."VAT_VIES",'
      '  "CUSTOMER"."TYPE"'
      'from "CUSTOMER" '
      'where'
      '  "CUSTOMER"."CUST_ID" = :"CUST_ID"')
    SelectSQL.Strings = (
      'SELECT * FROM CUSTOMER '
      'WHERE CUST_ID = :CUST_ID')
    ModifySQL.Strings = (
      'update "CUSTOMER"'
      'set'
      '  "CUSTOMER"."CUST_ID" = :"CUST_ID",'
      '  "CUSTOMER"."ALT_CUSTID" = :"ALT_CUSTID",'
      '  "CUSTOMER"."AFM" = :"AFM",'
      '  "CUSTOMER"."NAME" = :"NAME",'
      '  "CUSTOMER"."ADDRESS1" = :"ADDRESS1",'
      '  "CUSTOMER"."ADDRESS2" = :"ADDRESS2",'
      '  "CUSTOMER"."CITY" = :"CITY",'
      '  "CUSTOMER"."POSTCODE" = :"POSTCODE",'
      '  "CUSTOMER"."PHONE1" = :"PHONE1",'
      '  "CUSTOMER"."PHONE2" = :"PHONE2",'
      '  "CUSTOMER"."FAX" = :"FAX",'
      '  "CUSTOMER"."OCCUPATION" = :"OCCUPATION",'
      '  "CUSTOMER"."TAXOFFICE" = :"TAXOFFICE",'
      '  "CUSTOMER"."DETAILS" = :"DETAILS",'
      '  "CUSTOMER"."DISCOUNT" = :"DISCOUNT",'
      '  "CUSTOMER"."SECONDARY_EMAIL" = :"SECONDARY_EMAIL",'
      '  "CUSTOMER"."EMAIL" = :"EMAIL",'
      '  "CUSTOMER"."ORDER" = :"ORDER",'
      '  "CUSTOMER"."COUNTRY" = :"COUNTRY",'
      '  "CUSTOMER"."PAYMETH_ID" = :"PAYMETH_ID",'
      '  "CUSTOMER"."VAT_VIES" = :"VAT_VIES",'
      '  "CUSTOMER"."TYPE" = :"TYPE"'
      'where'
      '  "CUSTOMER"."CUST_ID" = :"OLD_CUST_ID"')
    ParamCheck = True
    UniDirectional = False
    GeneratorField.Field = 'CUST_ID'
    GeneratorField.Generator = 'GEN_CUSTOMER_ID'
    Left = 506
    Top = 104
    object DatasetCustomerCUST_ID: TIntegerField
      FieldName = 'CUST_ID'
      Origin = '"CUSTOMER"."CUST_ID"'
      ProviderFlags = [pfInUpdate, pfInWhere, pfInKey]
      Required = True
    end
    object DatasetCustomerAFM: TIBStringField
      FieldName = 'AFM'
      Origin = '"CUSTOMER"."AFM"'
    end
    object DatasetCustomerNAME: TIBStringField
      FieldName = 'NAME'
      Origin = '"CUSTOMER"."NAME"'
      Size = 50
    end
    object DatasetCustomerADDRESS1: TIBStringField
      FieldName = 'ADDRESS1'
      Origin = '"CUSTOMER"."ADDRESS1"'
      Size = 50
    end
    object DatasetCustomerADDRESS2: TIBStringField
      FieldName = 'ADDRESS2'
      Origin = '"CUSTOMER"."ADDRESS2"'
      Size = 50
    end
    object DatasetCustomerCITY: TIBStringField
      FieldName = 'CITY'
      Origin = '"CUSTOMER"."CITY"'
      Size = 30
    end
    object DatasetCustomerPOSTCODE: TIBStringField
      FieldName = 'POSTCODE'
      Origin = '"CUSTOMER"."POSTCODE"'
      Size = 10
    end
    object DatasetCustomerOCCUPATION: TIBStringField
      FieldName = 'OCCUPATION'
      Origin = '"CUSTOMER"."OCCUPATION"'
      Size = 120
    end
    object DatasetCustomerTAXOFFICE: TIBStringField
      FieldName = 'TAXOFFICE'
      Origin = '"CUSTOMER"."TAXOFFICE"'
      Size = 30
    end
    object DatasetCustomerDETAILS: TMemoField
      FieldName = 'DETAILS'
      Origin = '"CUSTOMER"."DETAILS"'
      ProviderFlags = [pfInUpdate]
      BlobType = ftMemo
      Size = 8
    end
    object DatasetCustomerEMAIL: TIBStringField
      FieldName = 'EMAIL'
      Origin = '"CUSTOMER"."EMAIL"'
      Size = 120
    end
    object DatasetCustomerDISCOUNT: TIBBCDField
      FieldName = 'DISCOUNT'
      Origin = '"CUSTOMER"."DISCOUNT"'
      Precision = 18
      Size = 2
    end
    object DatasetCustomerORDER: TIntegerField
      FieldName = 'ORDER'
      Origin = '"CUSTOMER"."ORDER"'
    end
    object DatasetCustomerCOUNTRY: TIBStringField
      FieldName = 'COUNTRY'
      Origin = '"CUSTOMER"."COUNTRY"'
      Size = 40
    end
    object DatasetCustomerALT_CUSTID: TIntegerField
      FieldName = 'ALT_CUSTID'
      Origin = '"CUSTOMER"."ALT_CUSTID"'
    end
    object DatasetCustomerPAYMETH_ID: TIntegerField
      FieldName = 'PAYMETH_ID'
      Origin = '"CUSTOMER"."PAYMETH_ID"'
    end
    object DatasetCustomerVAT_VIES: TIBStringField
      FieldName = 'VAT_VIES'
      Origin = '"CUSTOMER"."VAT_VIES"'
      Size = 30
    end
    object DatasetCustomerSECONDARY_EMAIL: TIBStringField
      FieldName = 'SECONDARY_EMAIL'
      Origin = '"CUSTOMER"."SECONDARY_EMAIL"'
      Size = 120
    end
    object DatasetCustomerFAX: TIBStringField
      FieldName = 'FAX'
      Origin = '"CUSTOMER"."FAX"'
      Size = 15
    end
    object DatasetCustomerPHONE1: TIBStringField
      FieldName = 'PHONE1'
      Origin = '"CUSTOMER"."PHONE1"'
      Size = 30
    end
    object DatasetCustomerPHONE2: TIBStringField
      FieldName = 'PHONE2'
      Origin = '"CUSTOMER"."PHONE2"'
      Size = 30
    end
    object DatasetCustomerTYPE: TIBStringField
      FieldName = 'TYPE'
      Origin = '"CUSTOMER"."TYPE"'
      Size = 60
    end
  end
end
