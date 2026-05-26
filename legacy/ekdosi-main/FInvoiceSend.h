//---------------------------------------------------------------------------

#ifndef FInvoiceSendH
#define FInvoiceSendH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvExExtCtrls.hpp"
#include "JvExMask.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvToolEdit.hpp"
#include <ComCtrls.hpp>
#include <ExtCtrls.hpp>
#include <Mask.hpp>
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExDBGrids.hpp"
#include <DB.hpp>
#include <DBGrids.hpp>
#include <Grids.hpp>
#include "JvDotNetControls.hpp"
#include "JvSpin.hpp"

#include "CNewSpecialForm.h"
#include "cxClasses.hpp"
#include "cxControls.hpp"
#include "cxCustomData.hpp"
#include "cxData.hpp"
#include "cxDataStorage.hpp"
#include "cxDBData.hpp"
#include "cxEdit.hpp"
#include "cxFilter.hpp"
#include "cxGraphics.hpp"
#include "cxGrid.hpp"
#include "cxGridCustomTableView.hpp"
#include "cxGridCustomView.hpp"
#include "cxGridDBTableView.hpp"
#include "cxGridLevel.hpp"
#include "cxGridTableView.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxStyles.hpp"
#include "cxNavigator.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <IBX.IBQuery.hpp>
#include "cxDataControllerConditionalFormattingRulesManagerDialog.hpp"
#include "dxSkinBlack.hpp"
#include "dxSkinBlue.hpp"
#include "dxSkinBlueprint.hpp"
#include "dxSkinCaramel.hpp"
#include "dxSkinCoffee.hpp"
#include "dxSkinDarkRoom.hpp"
#include "dxSkinDarkSide.hpp"
#include "dxSkinDevExpressDarkStyle.hpp"
#include "dxSkinDevExpressStyle.hpp"
#include "dxSkinFoggy.hpp"
#include "dxSkinGlassOceans.hpp"
#include "dxSkinHighContrast.hpp"
#include "dxSkiniMaginary.hpp"
#include "dxSkinLilian.hpp"
#include "dxSkinLiquidSky.hpp"
#include "dxSkinLondonLiquidSky.hpp"
#include "dxSkinMcSkin.hpp"
#include "dxSkinMetropolis.hpp"
#include "dxSkinMetropolisDark.hpp"
#include "dxSkinMoneyTwins.hpp"
#include "dxSkinOffice2007Black.hpp"
#include "dxSkinOffice2007Blue.hpp"
#include "dxSkinOffice2007Green.hpp"
#include "dxSkinOffice2007Pink.hpp"
#include "dxSkinOffice2007Silver.hpp"
#include "dxSkinOffice2010Black.hpp"
#include "dxSkinOffice2010Blue.hpp"
#include "dxSkinOffice2010Silver.hpp"
#include "dxSkinOffice2013DarkGray.hpp"
#include "dxSkinOffice2013LightGray.hpp"
#include "dxSkinOffice2013White.hpp"
#include "dxSkinOffice2016Colorful.hpp"
#include "dxSkinOffice2016Dark.hpp"
#include "dxSkinPumpkin.hpp"
#include "dxSkinsCore.hpp"
#include "dxSkinsDefaultPainters.hpp"
#include "dxSkinSeven.hpp"
#include "dxSkinSevenClassic.hpp"
#include "dxSkinSharp.hpp"
#include "dxSkinSharpPlus.hpp"
#include "dxSkinSilver.hpp"
#include "dxSkinSpringTime.hpp"
#include "dxSkinStardust.hpp"
#include "dxSkinSummer2008.hpp"
#include "dxSkinTheAsphaltWorld.hpp"
#include "dxSkinTheBezier.hpp"
#include "dxSkinValentine.hpp"
#include "dxSkinVisualStudio2013Blue.hpp"
#include "dxSkinVisualStudio2013Dark.hpp"
#include "dxSkinVisualStudio2013Light.hpp"
#include "dxSkinVS2010.hpp"
#include "dxSkinWhiteprint.hpp"
#include "dxSkinXmas2008Blue.hpp"
//---------------------------------------------------------------------------
class TFrmInvoiceSend : public NewSpecialForm
{
__published:	// IDE-managed Components
	TJvPanel *JvPanel1;
	TJvDateEdit *editDate;
	TLabel *Label2;
	TStatusBar *StatusBar1;
	TJvPanel *JvPanel2;
	TDataSource *DSProductQty;
	TIBDataSet *DatasetProductQty;
	TIBStringField *DatasetProductQtyDESCRIPTION_SHORT;
	TIntegerField *DatasetProductQtyBARCODE;
	TIntegerField *DatasetProductQtyPRODUCT_ID;
	TJvPanel *JvPanel3;
	TJvDotNetButton *cmdAccept;
	TJvDotNetButton *JvDotNetButton2;
	TJvDotNetButton *cmdPrint;
	TIBQuery *QryInvoice;
	TIntegerField *QryInvoiceINVOICE_ID;
	TIBStringField *QryInvoiceINVCODE;
	TIntegerField *QryInvoiceCUST_ID;
	TIBStringField *QryInvoiceINVTYPE;
	TDateField *QryInvoiceINVDATE;
	TSmallintField *QryInvoicePRINTED;
	TDateField *QryInvoiceDELIVERYDATE;
	TIntegerField *QryInvoiceDISTRAIM_ID;
	TIntegerField *QryInvoiceDELMETHOD_ID;
	TIntegerField *QryInvoicePAYMETH_ID;
	TIBBCDField *QryInvoiceDISCOUNT;
	TIBBCDField *QryInvoicePRICE;
	TIBBCDField *QryInvoicePRICEWVAT;
	TMemoField *QryInvoiceNOTES;
	TIntegerField *DatasetProductQtyINVOICE_ID;
	TLabel *Label1;
	TJvTimeEdit *editTime;
	TLabel *lblWarning;
	TIBBCDField *DatasetProductQtyQTY;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TcxGrid *GridItems;
	TcxGridDBTableView *ViewItems;
	TcxGridLevel *GridItemsLevel1;
	TcxGridDBColumn *ViewItemsBARCODE;
	TcxGridDBColumn *ViewItemsDESCRIPTION_SHORT;
	TcxGridDBColumn *ViewItemsQTY;
	void __fastcall GridProductQtysDblClick(TObject *Sender);
	void __fastcall GridProductQtysMouseDown(TObject *Sender, TMouseButton Button,
          TShiftState Shift, int X, int Y);
	void __fastcall DatasetProductQtyAfterPost(TDataSet *DataSet);
	void __fastcall JvDotNetButton2Click(TObject *Sender);
	void __fastcall cmdAcceptClick(TObject *Sender);
	void __fastcall editDateChange(TObject *Sender);
	void __fastcall GridProductQtysKeyDown(TObject *Sender, WORD &Key,
          TShiftState Shift);
	void __fastcall GridProductQtysExit(TObject *Sender);
private:	// User declarations
	TDate runningDate;
	int selRow, selCol;
	int invoice_id;
	int cumInvoiceId;
    void genInvoiceId();
	void fillProducts();
	int findCumInvoiceDate(TDate _date);
	void loadInvoice();
	AnsiString getReportFilename();
public:		// User declarations
	__fastcall TFrmInvoiceSend(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmInvoiceSend *FrmInvoiceSend;
//---------------------------------------------------------------------------
#endif
